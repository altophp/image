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

use Alto\Image\Analyzer\Raster;
use Alto\Image\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Raster::class)]
final class RasterBoundsTest extends TestCase
{
    #[DataProvider('invalidDimensions')]
    public function testDimensionsAreRejectedBeforePixelAllocation(int $width, int $height): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('raster');
        Raster::fromBmp(self::bmp($width, $height));
    }

    /**
     * @return iterable<array{int, int}>
     */
    public static function invalidDimensions(): iterable
    {
        yield [0, 1];
        yield [1, 0];
        yield [-1, 1];
        yield [65, 1];
        yield [1, 65];
        yield [2147483647, 2147483647];
        yield [1, -2147483648];
    }

    #[DataProvider('invalidPayloads')]
    public function testTruncatedAndOverlappingPixelDataIsRejected(int $offset, string $pixels): void
    {
        $this->expectException(InvalidArgumentException::class);
        Raster::fromBmp(self::bmp(1, 1, $offset, $pixels));
    }

    /**
     * @return iterable<array{int, string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield [54, ''];
        yield [54, "\x00\x00\xFF"];
        yield [53, "\x00\x00\xFF\x00"];
        yield [4294967295, "\x00\x00\xFF\x00"];
    }

    public function testValidTopDownAndBottomUpPayloadsStillDecode(): void
    {
        foreach ([1, -1] as $height) {
            $raster = Raster::fromBmp(self::bmp(1, $height, 54, "\x00\x00\xFF\x00"));
            self::assertSame([255, 0, 0, 255], $raster->rgba(0, 0));
        }
    }

    private static function bmp(int $width, int $height, int $offset = 54, string $pixels = ''): string
    {
        return 'BM' . pack('VvvV', 54 + strlen($pixels), 0, 0, $offset)
            . pack('V3v2V6', 40, $width, $height, 1, 24, 0, strlen($pixels), 0, 0, 0, 0) . $pixels;
    }
}
