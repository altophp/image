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

use Alto\Image\Analyzer\DominantColors;
use Alto\Image\Analyzer\PerceptualHash;
use Alto\Image\Analyzer\Raster;
use Alto\Image\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Analyzers are pure functions over bytes, testable with no extension.
 */
#[CoversClass(Raster::class)]
#[CoversClass(DominantColors::class)]
#[CoversClass(PerceptualHash::class)]
final class AnalyzerTest extends TestCase
{
    /**
     * The cap is the design. With it, every analyzer is forty lines of naive PHP
     * that costs nothing; without it, they all want a packed buffer.
     */
    public function testARasterIsCappedBySixtyFourByConstruction(): void
    {
        self::assertSame(64, Raster::MAX);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keeps analyzers naive and free');

        new Raster(65, 10, array_fill(0, 650, 0));
    }

    public function testARasterRefusesAPixelCountThatDoesNotMatchItsShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A 4x3 raster holds 12 pixels, got 5.');

        new Raster(4, 3, array_fill(0, 5, 0));
    }

    public function testItReadsAnUncompressedBmpInBothRowOrders(): void
    {
        $bottomUp = Raster::fromBmp($this->bmp(2, 2, [
            [0xFF0000, 0x00FF00],
            [0x0000FF, 0xFFFFFF],
        ]));

        self::assertSame(2, $bottomUp->width);
        self::assertSame([0xFF, 0x00, 0x00, 0xFF], $bottomUp->rgba(0, 0));
        self::assertSame([0xFF, 0xFF, 0xFF, 0xFF], $bottomUp->rgba(1, 1));
    }

    public function testItRefusesBytesThatAreNotABmp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('these bytes are not one');

        Raster::fromBmp('not a bitmap at all, just a sentence');
    }

    public function testItRefusesCompressedAndUnsupportedBmpPixels(): void
    {
        $bmp = $this->bmp(1, 1, [[0x123456]]);

        try {
            Raster::fromBmp(substr_replace($bmp, pack('V', 1), 30, 4));
            self::fail('A compressed BMP was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('compressed', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('24 or 32 bit');
        Raster::fromBmp(substr_replace($bmp, pack('v', 8), 28, 2));
    }

    public function testAZeroAlphaChannelInAThirtyTwoBitBmpMeansUnused(): void
    {
        $raster = Raster::fromBmp($this->bmp32(2, 1, [[0x00123456, 0x00ABCDEF]]));

        self::assertSame([0x12, 0x34, 0x56, 0xFF], $raster->rgba(0, 0));
        self::assertSame([0xAB, 0xCD, 0xEF, 0xFF], $raster->rgba(1, 0));
    }

    public function testLumaIsRec709(): void
    {
        $raster = new Raster(3, 1, [0xFFFF0000, 0xFF00FF00, 0xFF0000FF]);

        self::assertSame(0.2126 * 255, $raster->luma(0, 0));
        self::assertSame(0.7152 * 255, $raster->luma(1, 0));
        self::assertSame(0.0722 * 255, $raster->luma(2, 0));
    }

    public function testDominantColoursAreColoursTheImageActuallyHeld(): void
    {
        // Three quarters one red, one quarter one blue.
        $pixels = array_merge(
            array_fill(0, 48, 0xFFE63946),
            array_fill(0, 16, 0xFF1D3557),
        );

        $dominant = (new DominantColors(2))->analyze(new Raster(8, 8, $pixels));

        self::assertCount(2, $dominant);
        self::assertSame('#e63946', $dominant[0]['colour']);
        self::assertSame(0.75, $dominant[0]['share']);
        self::assertSame('#1d3557', $dominant[1]['colour']);
        self::assertSame(0.25, $dominant[1]['share']);
    }

    public function testDominantColourConfigurationHasUsefulBounds(): void
    {
        try {
            new DominantColors(0);
            self::fail('A zero colour result was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('at least one colour', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('falls in [2, 16]');
        new DominantColors(levels: 17);
    }

    /**
     * A pixel you cannot see is not a colour the image is made of.
     */
    public function testTransparentPixelsDoNotCount(): void
    {
        $dominant = (new DominantColors(2))->analyze(new Raster(4, 1, [
            0x00FFFFFF, 0x00FFFFFF, 0xFFE63946, 0xFFE63946,
        ]));

        self::assertCount(1, $dominant);
        self::assertSame(1.0, $dominant[0]['share']);
    }

    public function testAnEntirelyTransparentImageHasNoDominantColour(): void
    {
        self::assertSame([], (new DominantColors())->analyze(new Raster(2, 2, array_fill(0, 4, 0))));
    }

    public function testAPerceptualHashIsSixteenHexDigits(): void
    {
        $hash = (new PerceptualHash())->analyze($this->photograph());

        self::assertSame(16, \strlen($hash));
        self::assertSame(1, preg_match('/^[0-9a-f]{16}$/', $hash));
    }

    /**
     * The DCT variant rather than the average one, because a brightened copy is
     * the same picture and this package has to be able to say so.
     */
    public function testABrightenedCopyIsStillTheSamePicture(): void
    {
        $hash = new PerceptualHash();
        $original = $this->photograph();
        $brighter = new Raster($original->width, $original->height, array_map(
            static function (int $pixel): int {
                $lift = static fn(int $channel): int => min(255, $channel + 40);

                return (0xFF << 24)
                    | ($lift(($pixel >> 16) & 0xFF) << 16)
                    | ($lift(($pixel >> 8) & 0xFF) << 8)
                    | $lift($pixel & 0xFF);
            },
            $original->pixels,
        ));

        self::assertLessThanOrEqual(
            4,
            PerceptualHash::distance($hash->analyze($original), $hash->analyze($brighter)),
            'A brightened copy came out as a different picture.',
        );
    }

    public function testTwoDifferentPicturesAreFarApart(): void
    {
        $hash = new PerceptualHash();
        $stripes = [];
        $blocks = [];

        for ($y = 0; $y < 32; ++$y) {
            for ($x = 0; $x < 32; ++$x) {
                $stripes[] = 0 === $x % 2 ? 0xFF000000 : 0xFFFFFFFF;
                $blocks[] = $x < 16 === ($y < 16) ? 0xFF000000 : 0xFFFFFFFF;
            }
        }

        self::assertGreaterThan(
            8,
            PerceptualHash::distance(
                $hash->analyze(new Raster(32, 32, $stripes)),
                $hash->analyze(new Raster(32, 32, $blocks)),
            ),
        );
    }

    /**
     * The one input this kind of hash is bad at, stated rather than hidden.
     *
     * A near-linear gradient puts almost all its energy in the DC term, so the
     * sixty-odd coefficients that remain sit around the median and a rounding
     * error flips them. Measured, the same gradient at 80% gain comes out twelve
     * bits away, where a photograph comes out two.
     *
     * It matters because assertImageSimilar() is what the conformance kit uses to
     * compare two drivers, and a fixture that is a flat ramp will produce a
     * failure that is about the fixture rather than about the drivers.
     */
    public function testAGradientIsThePathologicalCaseAndItIsNotAHashBug(): void
    {
        $hash = new PerceptualHash();
        $gradient = new Raster(32, 32, $this->gradientPixels());
        $photograph = $this->photograph();

        $darken = static fn(Raster $r): Raster => new Raster($r->width, $r->height, array_map(
            static function (int $pixel): int {
                $gain = static fn(int $channel): int => (int) round($channel * 0.8);

                return (0xFF << 24)
                    | ($gain(($pixel >> 16) & 0xFF) << 16)
                    | ($gain(($pixel >> 8) & 0xFF) << 8)
                    | $gain($pixel & 0xFF);
            },
            $r->pixels,
        ));

        $onAPhotograph = PerceptualHash::distance($hash->analyze($photograph), $hash->analyze($darken($photograph)));
        $onAGradient = PerceptualHash::distance($hash->analyze($gradient), $hash->analyze($darken($gradient)));

        self::assertLessThanOrEqual(4, $onAPhotograph);
        self::assertGreaterThan($onAPhotograph, $onAGradient, 'The gradient is supposed to be the harder case.');
    }

    public function testComparingHashesOfDifferentLengthsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('different lengths cannot be compared');

        PerceptualHash::distance('abcd', 'abcdef');
    }

    public function testMalformedHashesAndOutOfBoundsPixelsAreRefused(): void
    {
        try {
            PerceptualHash::distance('zzzzzzzzzzzzzzzz', 'zzzzzzzzzzzzzzzz');
            self::fail('Malformed hexadecimal hashes were compared.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('falls outside');
        (new Raster(1, 1, [0xFFFFFFFF]))->at(1, 0);
    }

    public function testResamplingASquareIsIdempotentAtTheSameSize(): void
    {
        $raster = $this->photograph();

        self::assertSame($raster, $raster->resampledTo(32));
        self::assertSame(16, $raster->resampledTo(16)->width);
    }

    /**
     * Broadband content, which is what a photograph is.
     *
     * Not a linear gradient: a gradient puts nearly all its energy in the DC term
     * and the first few coefficients, leaving the other sixty hovering around the
     * median, where a rounding error flips a bit. See the test below, which says
     * so out loud rather than letting someone rediscover it.
     */
    private function photograph(): Raster
    {
        $pixels = [];

        for ($y = 0; $y < 32; ++$y) {
            for ($x = 0; $x < 32; ++$x) {
                $pixels[] = (0xFF << 24)
                    | ((int) (127 + 127 * sin($x / 2)) << 16)
                    | ((int) (127 + 127 * sin($y / 3)) << 8)
                    | (($x * $y ^ $x + $y) & 0xFF);
            }
        }

        return new Raster(32, 32, $pixels);
    }

    /**
     * @return list<int>
     */
    private function gradientPixels(): array
    {
        $pixels = [];

        for ($y = 0; $y < 32; ++$y) {
            for ($x = 0; $x < 32; ++$x) {
                $pixels[] = (0xFF << 24) | (($x * 8) << 16) | (($y * 8) << 8) | (($x ^ $y) * 4 & 0xFF);
            }
        }

        return $pixels;
    }

    /**
     * A 24-bit bottom-up BMP, hand-assembled, so this test needs no encoder.
     *
     * @param list<list<int>> $rows top row first, as 0xRRGGBB
     */
    private function bmp(int $width, int $height, array $rows): string
    {
        $stride = intdiv(24 * $width + 31, 32) * 4;
        $pixels = '';

        // BMP stores the bottom row first unless the height is negative.
        foreach (array_reverse($rows) as $row) {
            $line = '';

            foreach ($row as $colour) {
                $line .= \chr($colour & 0xFF) . \chr(($colour >> 8) & 0xFF) . \chr(($colour >> 16) & 0xFF);
            }

            $pixels .= str_pad($line, $stride, "\x00");
        }

        $info = pack('V', 40) . pack('ll', $width, $height) . pack('vv', 1, 24) . pack('V', 0)
            . pack('V', \strlen($pixels)) . pack('llVV', 2835, 2835, 0, 0);

        return 'BM' . pack('V', 14 + \strlen($info) + \strlen($pixels)) . pack('vv', 0, 0)
            . pack('V', 14 + \strlen($info)) . $info . $pixels;
    }

    /**
     * @param list<list<int>> $rows top row first, as 0xAARRGGBB
     */
    private function bmp32(int $width, int $height, array $rows): string
    {
        $pixels = '';

        foreach (array_reverse($rows) as $row) {
            foreach ($row as $colour) {
                $pixels .= pack('V', $colour);
            }
        }

        $info = pack('V', 40) . pack('ll', $width, $height) . pack('vv', 1, 32) . pack('V', 0)
            . pack('V', \strlen($pixels)) . pack('llVV', 2835, 2835, 0, 0);

        return 'BM' . pack('V', 14 + \strlen($info) + \strlen($pixels)) . pack('vv', 0, 0)
            . pack('V', 14 + \strlen($info)) . $info . $pixels;
    }
}
