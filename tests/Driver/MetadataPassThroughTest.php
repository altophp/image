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

namespace Alto\Image\Tests\Driver;

use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Image;
use Alto\Image\MetadataPolicy;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Encoding::class)]
final class MetadataPassThroughTest extends TestCase
{
    #[DataProvider('policies')]
    public function testPrivateMetadataOutsideTheHeaderIsRemoved(string $driver, MetadataPolicy $policy, string $type): void
    {
        if (!extension_loaded('gd') || !extension_loaded($driver)) {
            self::markTestSkipped('The fixture needs GD and the selected driver.');
        }
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $private = 'private-location';
        $text = 'zTXt' === $type ? "Comment\0\0" . gzcompress($private) : "XML:com.adobe.xmp\0\0\0\0\0" . $private;
        $bytes = substr($png, 0, -12) . self::chunk('ruSt', str_repeat('p', 4096)) . self::chunk($type, $text) . substr($png, -12);
        $request = Image::open(Source::bytes($bytes))->using('gd' === $driver ? new GdDriver() : new ImagickDriver());

        $result = $request->withMetadata($policy)->render();
        self::assertFalse($result->copied);
        self::assertStringNotContainsString($private, $result->bytes);
        self::assertStringNotContainsString($type, $result->bytes);
        self::assertSame('2x2', (string) $result->metadata->size);
        self::assertSame($bytes, $request->keepMetadata()->render()->bytes);
    }

    /**
     * @return iterable<string, array{string, MetadataPolicy, string}>
     */
    public static function policies(): iterable
    {
        foreach (['gd', 'imagick'] as $driver) {
            foreach ([MetadataPolicy::Strip, MetadataPolicy::ColourProfile, MetadataPolicy::Copyright] as $policy) {
                foreach (['iTXt', 'zTXt'] as $type) {
                    yield $driver . '-' . $policy->value . '-' . $type => [$driver, $policy, $type];
                }
            }
        }
    }

    private static function chunk(string $type, string $bytes): string
    {
        return pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
    }
}
