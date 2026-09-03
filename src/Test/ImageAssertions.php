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

namespace Alto\Image\Test;

use Alto\Image\Analyzer\PerceptualHash;
use Alto\Image\Analyzer\Raster;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\Internal\HeaderReader;
use Alto\Image\MetadataPolicy;
use Alto\Image\Scaling;
use Alto\Image\Size;
use Alto\Image\Source;
use PHPUnit\Framework\Assert;

/**
 * PHPUnit assertions for image dimensions and visual similarity.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
trait ImageAssertions
{
    /**
     * Two images are the same picture.
     *
     * The tolerance is in bits of a 64-bit perceptual hash. Zero is byte-identical
     * pixels; up to about six is the same picture through two different encoders
     * or two different but equally correct resampling kernels; past twelve is a
     * different picture.
     */
    protected static function assertImageSimilar(
        string $expected,
        string $actual,
        DriverInterface $driver,
        int $tolerance = 6,
        string $message = '',
    ): void {
        $distance = PerceptualHash::distance(
            self::perceptualHash($expected, $driver),
            self::perceptualHash($actual, $driver),
        );

        Assert::assertLessThanOrEqual($tolerance, $distance, \sprintf(
            '%sThe two images differ by %d bits of perceptual hash, and %d was the most that would still be the same picture.',
            '' === $message ? '' : $message . "\n",
            $distance,
            $tolerance,
        ));
    }

    protected static function assertImageDiffers(
        string $expected,
        string $actual,
        DriverInterface $driver,
        int $atLeast = 8,
        string $message = '',
    ): void {
        $distance = PerceptualHash::distance(
            self::perceptualHash($expected, $driver),
            self::perceptualHash($actual, $driver),
        );

        Assert::assertGreaterThanOrEqual($atLeast, $distance, \sprintf(
            '%sThe two images differ by only %d bits, so the operation did not visibly do anything.',
            '' === $message ? '' : $message . "\n",
            $distance,
        ));
    }

    /**
     * The encoded bytes really are the size they were promised to be.
     */
    protected static function assertImageSize(string $bytes, Size $expected, string $message = ''): void
    {
        $actual = HeaderReader::read($bytes, 'the encoded result')->size;

        Assert::assertTrue($actual->equals($expected), \sprintf(
            '%sExpected %s, and the encoded bytes are %s.',
            '' === $message ? '' : $message . "\n",
            $expected,
            $actual,
        ));
    }

    protected static function assertImageFormat(string $bytes, Format $expected, string $message = ''): void
    {
        Assert::assertSame($expected, HeaderReader::read($bytes, 'the encoded result')->format, $message);
    }

    /**
     * A downscaled one-pixel checkerboard converged on flat grey.
     *
     * Anything else is aliasing, and this is the assertion that turns "we chose
     * the right kernel" from a claim into a test. The spread is the difference
     * between the lightest and darkest pixel of the result; correct is zero.
     */
    protected static function assertNoMoire(string $bytes, DriverInterface $driver, int $maxSpread = 48, string $message = ''): void
    {
        $raster = self::raster($bytes, $driver);
        $lightest = 0.0;
        $darkest = 255.0;

        for ($y = 0; $y < $raster->height; ++$y) {
            for ($x = 0; $x < $raster->width; ++$x) {
                $luma = $raster->luma($x, $y);
                $lightest = max($lightest, $luma);
                $darkest = min($darkest, $luma);
            }
        }

        $spread = (int) round($lightest - $darkest);

        Assert::assertLessThanOrEqual($maxSpread, $spread, \sprintf(
            '%sA downscaled one-pixel checkerboard must converge on flat grey. '
            . 'This one has a spread of %d between its lightest and darkest pixel, which is moire.',
            '' === $message ? '' : $message . "\n",
            $spread,
        ));
    }

    /**
     * The image is one flat colour, within a tolerance per channel.
     */
    protected static function assertImageIsFlat(string $bytes, DriverInterface $driver, int $tolerance = 4, string $message = ''): void
    {
        $raster = self::raster($bytes, $driver);
        [$red, $green, $blue] = $raster->rgba(0, 0);
        $checked = 0;

        for ($y = 0; $y < $raster->height; ++$y) {
            for ($x = 0; $x < $raster->width; ++$x) {
                [$r, $g, $b] = $raster->rgba($x, $y);

                ++$checked;

                if (abs($r - $red) > $tolerance || abs($g - $green) > $tolerance || abs($b - $blue) > $tolerance) {
                    Assert::fail(\sprintf(
                        '%sExpected one flat colour, and the pixel at (%d, %d) is rgb(%d, %d, %d) against rgb(%d, %d, %d) at the origin.',
                        '' === $message ? '' : $message . "\n",
                        $x,
                        $y,
                        $r,
                        $g,
                        $b,
                        $red,
                        $green,
                        $blue,
                    ));
                }
            }
        }

        Assert::assertSame($raster->count(), $checked, 'The flatness check did not visit every pixel.');
    }

    protected static function perceptualHash(string $bytes, DriverInterface $driver): string
    {
        return (new PerceptualHash())->analyze(self::raster($bytes, $driver));
    }

    protected static function raster(string $bytes, DriverInterface $driver): Raster
    {
        return Raster::fromBmp(
            Image::open(Source::bytes($bytes))
                ->using($driver)
                ->fit(Raster::MAX, Raster::MAX, Scaling::Down)
                ->encode(Format::Bmp, metadata: MetadataPolicy::Strip)
                ->bytes(),
        );
    }
}
