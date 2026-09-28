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

use Alto\Image\Driver\Output;
use Alto\Image\Image;
use Alto\Image\Internal\AtomicWriter;
use Alto\Image\Internal\Fingerprint;
use Alto\Image\Source;
use Alto\Image\Store\FlysystemStore;
use Alto\Image\Store\LocalStore;
use Alto\Image\Tests\Support\ArrayDriver;
use Alto\Image\Transform;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A directory of files keyed by a signature, and the only stateful thing here.
 */
#[CoversClass(LocalStore::class)]
#[CoversClass(FlysystemStore::class)]
#[CoversClass(AtomicWriter::class)]
#[CoversClass(Image::class)]
#[CoversClass(Output::class)]
#[CoversClass(Transform::class)]
#[CoversClass(Fingerprint::class)]
final class StoreLockTest extends TestCase
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

    public function testTheLockCoversRecheckingAndPublishingTheFiles(): void
    {
        $driver = new ArrayDriver();
        $image = Image::open($this->source())->using($driver)->widths(320, 640)->png();
        $plain = new LocalStore($this->root);
        [$first, $second] = array_map($plain->path(...), $image->images());
        $store = new LocalStore($this->root, static function (string $key, \Closure $work) use ($first, $second): mixed {
            // Another worker published the first derivative while this one waited.
            if (!is_dir(\dirname($first))) {
                mkdir(\dirname($first), 0o777, true);
            }
            file_put_contents($first, 'already generated');
            $results = $work();
            self::assertFileExists($second, 'Publishing must happen before releasing the lock.');

            return $results;
        });

        self::assertSame([$first, $second], $store->ensureMany($image));
        self::assertSame('already generated', file_get_contents($first));
        self::assertCount(1, $driver->calls());
    }

    public function testOverlappingBatchesUseTheSameLockKey(): void
    {
        $keys = [];
        $image = Image::open($this->source())->using(new ArrayDriver())->png();
        $store = new LocalStore($this->root, static function (string $key, \Closure $work) use (&$keys): mixed {
            $keys[] = $key;

            return $work();
        });
        $store->ensureMany($image->widths(320, 640));
        $store->ensureMany($image->widths(640, 960));

        self::assertCount(2, $keys);
        self::assertSame($keys[0], $keys[1]);
    }

    public function testTheFlysystemLockCoversRecheckingAndPublishingTheFiles(): void
    {
        $driver = new ArrayDriver();
        $image = Image::open($this->source())->using($driver)->widths(320, 640)->png();
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));
        $plain = new FlysystemStore($filesystem);
        [$first, $second] = array_map($plain->path(...), $image->images());
        $store = new FlysystemStore($filesystem, criticalSection: static function (string $key, \Closure $work) use ($first, $second, $filesystem): mixed {
            // Another worker published the first derivative while this one waited.
            $filesystem->write($first, 'already generated');
            $results = $work();
            self::assertTrue($filesystem->fileExists($second), 'Publishing must happen before releasing the lock.');

            return $results;
        });

        self::assertSame([$first, $second], $store->ensureMany($image));
        self::assertSame('already generated', $filesystem->read($first));
        self::assertCount(1, $driver->calls());
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
