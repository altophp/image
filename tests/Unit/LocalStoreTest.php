<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Image\Tests\Unit;

use Alto\Image\Exception\StoreException;
use Alto\Image\Image;
use Alto\Image\Internal\AtomicWriter;
use Alto\Image\Source;
use Alto\Image\Store\LocalStore;
use Alto\Image\Test\ArrayDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A directory of files keyed by a signature, and the only stateful thing here.
 */
#[CoversClass(LocalStore::class)]
#[CoversClass(AtomicWriter::class)]
final class LocalStoreTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/alto-store-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->sweep($this->root);
    }

    /**
     * The claim that makes a store usable from a template: a URL is computable
     * with nothing on disk and nothing generated.
     */
    public function testThePathIsComputableWithNothingOnDisk(): void
    {
        $store = new LocalStore($this->root);
        $image = Image::open($this->source())->using(new ArrayDriver())->cover(1280, 720)->webp();

        $path = $store->path($image);

        self::assertSame($this->root, $store->root());
        self::assertFalse(is_dir($this->root), 'Asking for a path created a directory.');
        self::assertFalse($store->has($image));
        self::assertSame($path, $store->path($image), 'The path is not deterministic.');
        self::assertStringEndsWith('.webp', $path);
        self::assertStringContainsString('photo-', $path, 'A path should be greppable by source name.');
        self::assertStringContainsString($image->signature(), $path);
    }

    public function testTheFilesystemRootIsNotAStoreDestination(): void
    {
        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('other than the filesystem root');

        new LocalStore('/');
    }

    public function testThePathShardsSoOneDirectoryDoesNotHoldEverything(): void
    {
        $store = new LocalStore($this->root);
        $shards = [];

        foreach (range(100, 160) as $width) {
            $image = Image::open($this->source())->using(new ArrayDriver())->cover($width, $width)->webp();
            $shards[] = basename(\dirname($store->path($image)));
        }

        self::assertGreaterThan(4, \count(array_unique($shards)), 'Every derivative landed in one directory.');
    }

    public function testEnsureWritesWhatIsMissingAndNothingElse(): void
    {
        $driver = new ArrayDriver();
        $store = new LocalStore($this->root);
        $hero = Image::open($this->source())->using($driver)->cover(ratio: 16 / 9)->widths(320, 640, 960)->webp();

        $written = $store->ensureMany($hero);

        self::assertCount(3, $written);
        self::assertCount(3, $driver->calls(), 'The driver was asked for more than the three that were missing.');
        self::assertSame(1, $driver->batches(), 'The images were not rendered in one driver call.');

        foreach ($written as $result) {
            self::assertNotNull($result->path);
            self::assertFileExists($result->path);
            self::assertSame($result->bytes, file_get_contents($result->path));
        }

        // Second time: everything is there, so nothing reaches the driver at all.
        $driver->forget();
        $again = $store->ensureMany($hero);

        self::assertSame([], $driver->calls(), 'A second ensure() decoded something.');
        self::assertSame(0, $driver->batches(), 'A second ensure() reached the driver.');
        self::assertCount(3, $again);

        foreach ($again as $result) {
            self::assertTrue($result->copied);
        }
    }

    public function testImagesAndImageSetsStoreThemselves(): void
    {
        $driver = new ArrayDriver();
        $store = new LocalStore($this->root);
        $image = Image::open($this->source())->using($driver)->cover(320, 180)->webp();

        self::assertNotNull($image->store($store)->path);

        $images = $image->widths(160, 320);
        $results = $images->store($store);

        self::assertCount(2, $results);
        self::assertNotNull($results[0]->path);
        self::assertNotNull($results[1]->path);
    }

    public function testAPathIsTheLocalStoreShortcutForImagesAndImageSets(): void
    {
        $driver = new ArrayDriver();
        $root = $this->root . '/shortcut';
        $image = Image::open($this->source())->using($driver)->cover(320, 180)->webp();

        $one = $image->store($root);
        $many = $image->widths(160, 320)->store($root);

        self::assertNotNull($one->path);
        self::assertStringStartsWith($root . '/', $one->path);
        self::assertCount(2, $many);

        foreach ($many as $result) {
            self::assertNotNull($result->path);
            self::assertStringStartsWith($root . '/', $result->path);
            self::assertFileExists($result->path);
        }
    }

    /**
     * Rendering four rungs to write one is exactly the waste a store exists to
     * avoid, so a partial hit asks for the missing rungs only.
     */
    public function testAPartialHitOnlyRendersWhatIsMissing(): void
    {
        $driver = new ArrayDriver();
        $store = new LocalStore($this->root);

        $store->ensureMany(Image::open($this->source())->using($driver)->cover(ratio: 16 / 9)->widths(320)->webp());
        $driver->forget();

        $store->ensureMany(Image::open($this->source())->using($driver)->cover(ratio: 16 / 9)->widths(320, 640, 960)->webp());

        self::assertSame(['640x360', '960x540'], $driver->outputs());
    }

    public function testEnsureKeepsTheOrderTheImageAsksFor(): void
    {
        $store = new LocalStore($this->root);
        $hero = Image::open($this->source('a'))
            ->using(new ArrayDriver())
            ->cover(ratio: 16 / 9)
            ->widths(320, 640)
            ->formats(\Alto\Image\Format::Webp, \Alto\Image\Format::Avif);

        self::assertSame(
            ['320x180', '320x180', '640x360', '640x360'],
            array_map(static fn($r): string => (string) $r->size(), $store->ensureMany($hero)),
        );
    }

    /**
     * A lock saves duplicated CPU. It is not what makes the herd harmless: the
     * atomic rename is, and this checks the store honours one when given one.
     */
    public function testACriticalSectionIsUsedWhenOneIsGiven(): void
    {
        $keys = [];
        $store = new LocalStore($this->root, function (string $key, \Closure $work) use (&$keys): mixed {
            $keys[] = $key;

            return $work();
        });

        $store->ensureOne(Image::open($this->source())->using(new ArrayDriver())->cover(320, 180)->webp());

        self::assertCount(1, $keys);
        self::assertStringStartsWith('alto-image-', $keys[0]);
    }

    public function testACriticalSectionThatDoesNotReturnTheWorkIsCaught(): void
    {
        $store = new LocalStore($this->root, static fn(string $key, \Closure $work): mixed => null);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('The critical section returned null');

        $store->ensureOne(Image::open($this->source())->using(new ArrayDriver())->cover(320, 180)->webp());
    }

    public function testACriticalSectionMustReturnOnlyResults(): void
    {
        $store = new LocalStore($this->root, static fn(string $key, \Closure $work): array => ['not a result']);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('not a list of Results');

        $store->ensureOne(Image::open($this->source())->using(new ArrayDriver())->cover(320, 180)->webp());
    }

    public function testPruneRemovesWhatIsOldAndSweepsTheEmptyShards(): void
    {
        $store = new LocalStore($this->root);
        $hero = Image::open($this->source())->using(new ArrayDriver())->cover(ratio: 16 / 9)->widths(320, 640)->webp();

        $store->ensureMany($hero);

        self::assertSame(0, $store->prune(new \DateTimeImmutable('-1 hour')), 'Fresh derivatives were pruned.');
        self::assertSame(2, $store->prune(new \DateTimeImmutable('+1 hour')));
        self::assertSame([], glob($this->root . '/*/*') ?: []);
    }

    public function testPruningAStoreThatDoesNotExistIsNotAnError(): void
    {
        self::assertSame(0, (new LocalStore($this->root . '/never-created'))->prune(new \DateTimeImmutable()));
    }

    /**
     * The destination is never opened for writing: a temp file is, then renamed.
     */
    public function testTheWriterLeavesNothingBehind(): void
    {
        $path = $this->root . '/deep/nested/file.bin';

        AtomicWriter::write($path, 'the complete contents');

        self::assertSame('the complete contents', file_get_contents($path));
        self::assertSame([], glob($this->root . '/deep/nested/.alto*') ?: [], 'A temp file was left behind.');
        self::assertTrue((bool) (fileperms($path) & 0o044), 'The file is not readable by anyone but its owner.');
    }

    public function testAnOverwriteIsAlsoAtomic(): void
    {
        $path = $this->root . '/file.bin';

        AtomicWriter::write($path, 'first');
        AtomicWriter::write($path, 'second, and longer');

        self::assertSame('second, and longer', file_get_contents($path));
        self::assertCount(1, glob($this->root . '/*') ?: []);
    }

    public function testARenameFailureRemovesItsTemporaryFile(): void
    {
        $destination = $this->root . '/occupied';
        mkdir($destination, 0o777, true);

        try {
            AtomicWriter::write($destination, 'bytes');
            self::fail('A file replaced a directory.');
        } catch (StoreException $error) {
            self::assertStringContainsString('rename the temporary file', $error->getMessage());
        }

        self::assertSame([], glob($this->root . '/.alto*') ?: []);
    }

    public function testAnUnwritableDestinationSaysWhereToLook(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('Check the directory exists and is writable');

        AtomicWriter::write('/proc/alto/nope/file.bin', 'x');
    }

    private function source(string $name = 'photo'): Source
    {
        $ihdr = pack('NN', 1200, 800) . "\x08\x02\x00\x00\x00";
        $salt = 'photo' === $name ? '' : $name;
        $text = '' === $salt ? '' : pack('N', \strlen($salt)) . 'tEXt' . $salt . pack('N', crc32('tEXt' . $salt));

        return Source::bytes(
            "\x89PNG\x0D\x0A\x1A\x0A" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . $text . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82",
            $name,
        );
    }

    private function sweep(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->sweep($entry) : @unlink($entry);
        }

        @rmdir($directory);
    }
}
