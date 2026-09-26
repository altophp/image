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

use Alto\Image\Analyzer\AnalyzerInterface;
use Alto\Image\Analyzer\DominantColors;
use Alto\Image\Analyzer\Raster;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Gd\GdPipeline;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Driver\Imagick\ImagickPipeline;
use Alto\Image\Image;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Image::class)]
#[CoversClass(Raster::class)]
#[CoversClass(DominantColors::class)]
#[CoversClass(GdDriver::class)]
#[CoversClass(GdPipeline::class)]
#[CoversClass(ImagickDriver::class)]
#[CoversClass(ImagickPipeline::class)]
final class ImageAnalysisTest extends TestCase
{
    #[DataProvider('drivers')]
    public function testAnalyzeReceivesACappedRasterWithTheSourceRatio(string $driver): void
    {
        $probe = new class implements AnalyzerInterface {
            /**
             * @return array{int, int, int}
             */
            public function analyze(Raster $raster): array
            {
                return [$raster->width, $raster->height, $raster->count()];
            }
        };

        $dimensions = Image::open(Source::bytes($this->stripedBmp()))
            ->using($this->driver($driver))
            ->analyze($probe);

        self::assertSame([64, 21, 1344], $dimensions);
    }

    #[DataProvider('drivers')]
    public function testAnalyzeRunsTheRequestedTransformBeforeInspectingPixels(string $driver): void
    {
        $colours = Image::open(Source::bytes($this->stripedBmp()))
            ->using($this->driver($driver))
            ->crop(32, 32, x: 64, y: 0)
            ->analyze(new DominantColors(1));

        self::assertSame([['colour' => '#0000ff', 'packed' => 0xFF0000FF, 'share' => 1.0]], $colours);
    }

    public function testImageSetAnalysisUsesIndividualImages(): void
    {
        $set = Image::open(Source::bytes($this->stripedBmp()))->using($this->driver('gd'))->widths(16, 32);

        foreach ($set as $image) {
            self::assertNotEmpty($image->analyze(new DominantColors()));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'GD' => ['gd'];
        yield 'ImageMagick' => ['imagick'];
    }

    #[DataProvider('drivers')]
    public function testAnalysisKeepsTheRequestedCover(string $driver): void
    {
        $image = Image::open(Source::bytes($this->stripedBmp()))
            ->using($this->driver($driver))
            ->cover(32, 32, gravity: \Alto\Image\Anchor::TopLeft);

        self::assertSame([['colour' => '#ff0000', 'packed' => 0xFFFF0000, 'share' => 1.0]], $image->analyze(new DominantColors(3)));
    }

    #[DataProvider('drivers')]
    public function testAnalysisAcceptsAnOptimizedPalettePng(string $driver): void
    {
        $pixels = $this->driver('imagick');
        $bytes = Image::open(Source::bytes($this->stripedBmp()))->using($pixels)
            ->cover(32, 32, gravity: \Alto\Image\Anchor::TopLeft)->png()->bytes();
        $image = Image::open(Source::bytes($bytes))->using($this->driver($driver));

        self::assertSame([['colour' => '#ff0000', 'packed' => 0xFFFF0000, 'share' => 1.0]], $image->analyze(new DominantColors()));
    }

    #[DataProvider('drivers')]
    public function testPngByteCeilingIsEnforced(string $driver): void
    {
        $image = Image::open(Source::bytes($this->stripedBmp()))->using($this->driver($driver))
            ->encode(\Alto\Image\Format::Png, maxBytes: 1);

        $this->expectException(\Alto\Image\Exception\DriverException::class);
        $this->expectExceptionMessage('byte ceiling');
        $image->bytes();
    }

    #[DataProvider('drivers')]
    public function testPngUnderItsCeilingIsWritten(string $driver): void
    {
        $bytes = Image::open(Source::bytes($this->stripedBmp()))->using($this->driver($driver))
            ->encode(\Alto\Image\Format::Png, maxBytes: 4096)->bytes();

        self::assertLessThanOrEqual(4096, \strlen($bytes));
        self::assertSame(\Alto\Image\Format::Png, Source::bytes($bytes)->metadata()->format);
    }

    private function driver(string $name): DriverInterface
    {
        if ('gd' === $name) {
            if (!GdDriver::isAvailable()) {
                self::markTestSkipped('GD analysis integration needs ext-gd.');
            }

            return new GdDriver();
        }

        if (!ImagickDriver::isAvailable()) {
            self::markTestSkipped('ImageMagick analysis integration needs ext-imagick.');
        }

        return new ImagickDriver();
    }

    /**
     * A 96x32 BMP split into equal red, green and blue vertical bands.
     */
    private function stripedBmp(): string
    {
        $width = 96;
        $height = 32;
        $stride = intdiv(24 * $width + 31, 32) * 4;
        $pixels = '';

        for ($y = 0; $y < $height; ++$y) {
            $row = '';

            for ($x = 0; $x < $width; ++$x) {
                $colour = $x < 32 ? 0xFF0000 : ($x < 64 ? 0x00FF00 : 0x0000FF);
                $row .= \chr($colour & 0xFF) . \chr(($colour >> 8) & 0xFF) . \chr(($colour >> 16) & 0xFF);
            }

            $pixels .= str_pad($row, $stride, "\x00");
        }

        $info = pack('V', 40) . pack('ll', $width, $height) . pack('vv', 1, 24) . pack('V', 0)
            . pack('V', \strlen($pixels)) . pack('llVV', 2835, 2835, 0, 0);

        return 'BM' . pack('V', 14 + \strlen($info) + \strlen($pixels)) . pack('vv', 0, 0)
            . pack('V', 14 + \strlen($info)) . $info . $pixels;
    }
}
