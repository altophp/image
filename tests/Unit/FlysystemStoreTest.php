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
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\Source;
use Alto\Image\Store\FlysystemStore;
use Alto\Image\Test\ArrayDriver;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlysystemStore::class)]
final class FlysystemStoreTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/alto-flysystem-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->sweep($this->root);
    }

    public function testPathsAreDeterministicPrefixedAndSlugged(): void
    {
        $store = $this->store('derivatives');
        $image = Image::open($this->source('My Hero'))->using(new ArrayDriver())->cover(320, 180)->webp();

        $path = $store->path($image);

        self::assertStringStartsWith('derivatives/', $path);
        self::assertStringContainsString('my-hero-', $path);
        self::assertStringEndsWith('.webp', $path);
        self::assertSame($path, $store->path($image));
        self::assertSame('derivatives', $store->prefix());
        self::assertFalse($store->has($image));
    }

    public function testEnsureWritesOnlyMissingImagesAndReadsExistingOnes(): void
    {
        $driver = new ArrayDriver();
        $store = $this->store('cache');
        $images = Image::open($this->source())->using($driver)->cover(ratio: 16 / 9)->widths(320, 640)->webp();

        $written = $store->ensureMany($images);

        self::assertSame(1, $driver->batches());
        self::assertSame(['320x180', '640x360'], $driver->outputs());
        self::assertCount(2, $written);
        self::assertTrue($store->has($images->images()[0]));

        $driver->forget();
        $existing = $store->ensureMany($images);

        self::assertSame(0, $driver->batches());
        self::assertTrue($existing[0]->copied);
        self::assertTrue($existing[1]->copied);
        self::assertSame($written[0]->bytes, $existing[0]->bytes);
    }

    public function testACriticalSectionReceivesOneStableBatchKey(): void
    {
        $keys = [];
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));
        $store = new FlysystemStore($filesystem, criticalSection: function (string $key, \Closure $work) use (&$keys): mixed {
            $keys[] = $key;

            return $work();
        });

        $store->ensureOne(Image::open($this->source())->using(new ArrayDriver())->webp());

        self::assertCount(1, $keys);
        self::assertStringStartsWith('alto-image-', $keys[0]);
    }

    public function testAnInvalidCriticalSectionResultIsRejected(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));
        $store = new FlysystemStore($filesystem, criticalSection: static fn(string $key, \Closure $work): mixed => null);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('returned null');

        $store->ensureOne(Image::open($this->source())->using(new ArrayDriver())->webp());
    }

    public function testACriticalSectionMustReturnResults(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));
        $store = new FlysystemStore($filesystem, criticalSection: static fn(string $key, \Closure $work): array => [new \stdClass()]);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('not a list of Results');

        $store->ensureOne(Image::open($this->source())->using(new ArrayDriver())->webp());
    }

    public function testHasTranslatesFilesystemFailures(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('fileExists')->willThrowException(UnableToCheckFileExistence::forLocation('x'));
        $store = new FlysystemStore($filesystem);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('whether a derivative exists');
        $store->has(Image::open($this->source()));
    }

    public function testEnsureTranslatesStatFailures(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('fileExists')->willThrowException(UnableToCheckFileExistence::forLocation('x'));
        $store = new FlysystemStore($filesystem);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('Could not stat');
        $store->ensureOne(Image::open($this->source()));
    }

    public function testEnsureTranslatesWriteFailures(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('fileExists')->willReturn(false);
        $filesystem->expects(self::once())->method('write')->willThrowException(UnableToWriteFile::atLocation('x', 'denied'));
        $store = new FlysystemStore($filesystem);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('Could not write');
        $store->ensureOne(Image::open($this->source())->using(new ArrayDriver())->webp());
    }

    public function testEnsureTranslatesReadFailures(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('fileExists')->willReturn(true);
        $filesystem->expects(self::once())->method('read')->willThrowException(UnableToReadFile::fromLocation('x', 'denied'));
        $store = new FlysystemStore($filesystem);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('Could not read');
        $store->ensureOne(Image::open($this->source())->webp());
    }

    public function testPruneTranslatesListingFailures(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('listContents')->willThrowException(UnableToListContents::atLocation('', true, new \RuntimeException('denied')));
        $store = new FlysystemStore($filesystem);

        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('Could not prune');
        $store->prune(new \DateTimeImmutable());
    }

    public function testPruneUsesObjectModificationTimesAndSkipsDirectories(): void
    {
        $store = $this->store('cache');
        $store->ensureMany(Image::open($this->source())->using(new ArrayDriver())->formats(Format::Webp, Format::Avif));

        self::assertSame(0, $store->prune(new \DateTimeImmutable('-1 hour')));
        self::assertSame(2, $store->prune(new \DateTimeImmutable('+1 hour')));
    }

    private function store(string $prefix = ''): FlysystemStore
    {
        return new FlysystemStore(new Filesystem(new LocalFilesystemAdapter($this->root)), $prefix);
    }

    private function source(string $name = 'photo'): Source
    {
        $ihdr = pack('NN', 1200, 800) . "\x08\x02\x00\x00\x00";

        return Source::bytes(
            "\x89PNG\x0D\x0A\x1A\x0A" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82",
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
