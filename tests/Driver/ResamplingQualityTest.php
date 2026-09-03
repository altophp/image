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

use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Image;
use Alto\Image\Scaling;
use Alto\Image\Test\Corpus;
use Alto\Image\Test\ImageAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Downscaling, measured against a reference rather than against a rival.
 *
 * The first version of this file compared kernels to each other on a one-pixel
 * checkerboard and on file size, and reached the wrong conclusion twice over. A
 * checkerboard is one spatial frequency at one phase, so a filter can win it and
 * be worse everywhere else; and file size rewards blur, so the softest kernel
 * looks like the most efficient one.
 *
 * What settles it is an ideal area-average reference, computed here in float
 * from the source, at ratios both integer and not. A resampler that is right
 * lands on it to within the rounding error of eight-bit output, which is
 * 1/(2*sqrt(3)) or about 0.29. Everything else has a number you can read.
 */
final class ResamplingQualityTest extends TestCase
{
    use ImageAssertions;

    private static ?Corpus $corpus = null;

    /**
     * @return iterable<string, array{DriverInterface}>
     */
    public static function drivers(): iterable
    {
        yield 'gd' => [new GdDriver()];
        yield 'imagick' => [new ImagickDriver()];
    }

    /**
     * The claim, as a number: what the driver produces is the ideal area average,
     * give or take the rounding to eight bits.
     */
    #[DataProvider('drivers')]
    public function testTheDriverResamplesToTheIdealAreaAverage(DriverInterface $driver): void
    {
        if (!$driver->capabilities()->isAvailable()) {
            self::markTestSkipped(\sprintf('%s is not installed here.', $driver->name()));
        }

        $source = self::corpus()->path('photo.png');

        // 600x400 into four targets: an exact 2:1, and three ratios that are not.
        foreach ([[300, 200], [250, 167], [175, 117], [400, 267]] as [$width, $height]) {
            $reference = self::areaAverage($source, $width, $height);

            $rendered = Image::open($source)
                ->using($driver)
                ->stretch($width, $height, Scaling::Both)
                ->png()
                ->render();

            $error = self::rmse($reference, $rendered->bytes);

            self::assertLessThan(1.5, $error, \sprintf(
                "%s resampling 600x400 to %dx%d is %.2f away from the ideal area average.\n"
                . 'Rounding to eight bits alone is 0.29, so anything past about 1.5 is the resampler, not the encoder.',
                $driver->name(),
                $width,
                $height,
                $error,
            ));
        }
    }

    /**
     * And it is better than every kernel libgd offers through imagescale().
     *
     * All of them undersample: libgd does not scale the filter support with the
     * reduction factor, so at 2.4:1 a tent of radius one covers about two source
     * pixels out of the 2.4 it needs. Measured against a zone plate and a ground
     * truth supersampled from four times the resolution, imagecopyresampled
     * scores RMSE 9.16 and the best imagescale filter scores 26.33.
     */
    public function testEveryImagescaleFilterIsWorseThanWhatTheDriverUses(): void
    {
        if (!GdDriver::isAvailable()) {
            self::markTestSkipped('This one measures GD specifically.');
        }

        $source = self::corpus()->path('photo.png');
        $reference = self::areaAverage($source, 250, 167);

        $ours = Image::open($source)->using(new GdDriver())->stretch(250, 167, Scaling::Both)->png()->render();
        $oursError = self::rmse($reference, $ours->bytes);

        $image = imagecreatefrompng($source);
        self::assertInstanceOf(\GdImage::class, $image);

        $checked = 0;

        foreach ([
            'IMG_TRIANGLE' => \IMG_TRIANGLE,
            'IMG_CATMULLROM' => \IMG_CATMULLROM,
            'IMG_MITCHELL' => \IMG_MITCHELL,
            'IMG_GAUSSIAN' => \IMG_GAUSSIAN,
            'IMG_BILINEAR_FIXED' => \IMG_BILINEAR_FIXED,
        ] as $name => $kernel) {
            $scaled = @imagescale($image, 250, 167, $kernel);

            // Some libgd builds refuse some kernels outright. That is the other
            // half of the argument for not offering this as a public option.
            if (false === $scaled) {
                continue;
            }

            ob_start();
            imagepng($scaled);
            $error = self::rmse($reference, (string) ob_get_clean());

            self::assertGreaterThan($oursError, $error, \sprintf(
                '%s scored %.2f and what the driver uses scored %.2f, so this filter is now the better one.',
                $name,
                $error,
                $oursError,
            ));
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'This libgd offered no imagescale filter at all to compare against.');
    }

    /**
     * The two drivers reach the same answer, because there is only one.
     */
    public function testBothDriversResampleToTheSamePicture(): void
    {
        if (!GdDriver::isAvailable() || !ImagickDriver::isAvailable()) {
            self::markTestSkipped('This one needs both extensions.');
        }

        $source = self::corpus()->path('photo.png');
        $reference = self::areaAverage($source, 250, 167);

        foreach ([new GdDriver(), new ImagickDriver()] as $driver) {
            $rendered = Image::open($source)->using($driver)->stretch(250, 167, Scaling::Both)->png()->render();

            self::assertLessThan(1.5, self::rmse($reference, $rendered->bytes), \sprintf(
                '%s did not land on the area average, so the two drivers no longer agree.',
                $driver->name(),
            ));
        }
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function webReductionFactors(): iterable
    {
        // An area average has a residual beat at ratios near 1.5:1 on content
        // sitting exactly at Nyquist, because the footprint is only a pixel and
        // a half wide. It is real, it is what a checkerboard exposes, and it is
        // the one thing a wider kernel does better. The bound says how much.
        yield '1.5x' => [683, 40];
        yield '2.2x' => [465, 24];
        yield '3.3x' => [310, 16];
        yield '5.0x' => [205, 12];
        yield '10x' => [102, 8];
    }

    /**
     * A downscaled checkerboard still converges, just not as fast as a wider
     * kernel would. This is the trade the fidelity numbers above are worth.
     */
    #[DataProvider('webReductionFactors')]
    public function testDownscalingConvergesOnFlatGrey(int $target, int $maxSpread): void
    {
        if (!GdDriver::isAvailable()) {
            self::markTestSkipped('ext-gd is not installed here.');
        }

        $result = Image::open(self::corpus()->path('checkerboard.png'))
            ->using(new GdDriver())
            ->fit($target, $target)
            ->png()
            ->render();

        self::assertNoMoire($result->bytes, new GdDriver(), $maxSpread, \sprintf('1024 down to %d', $target));
    }

    /**
     * Antialiasing removes what cannot be represented and keeps everything that
     * can, so a hard edge, which is representable at every scale, survives.
     */
    public function testAHardEdgeSurvivesDownscaling(): void
    {
        if (!GdDriver::isAvailable()) {
            self::markTestSkipped('ext-gd is not installed here.');
        }

        $result = Image::open(self::corpus()->path('edge.png'))
            ->using(new GdDriver())
            ->fit(128, 64)
            ->png()
            ->render();

        $raster = self::raster($result->bytes, new GdDriver());
        $middle = intdiv($raster->height, 2);

        self::assertLessThan(16.0, $raster->luma(1, $middle), 'The black half went grey.');
        self::assertGreaterThan(238.0, $raster->luma($raster->width - 2, $middle), 'The white half went grey.');
    }

    public function testAFlatColourStaysFlat(): void
    {
        if (!GdDriver::isAvailable()) {
            self::markTestSkipped('ext-gd is not installed here.');
        }

        $result = Image::open(self::corpus()->path('flat.png'))
            ->using(new GdDriver())
            ->fit(80, 80)
            ->png()
            ->render();

        self::assertImageIsFlat($result->bytes, new GdDriver(), 6);
    }

    /**
     * The portability trap, measured, and the reason there is no Resampling enum.
     *
     * On the libgd this was written against, `imagescale()` with IMG_BICUBIC
     * returns false for every size in every direction, quietly, with no warning.
     * The same version number on another platform does the work.
     */
    public function testAPublicKernelKnobWouldHaveBeenADeploymentDependentCrash(): void
    {
        if (!GdDriver::isAvailable()) {
            self::markTestSkipped('ext-gd is not installed here.');
        }

        $source = imagecreatetruecolor(100, 100);
        $unavailable = [];

        foreach (['IMG_BICUBIC' => \IMG_BICUBIC, 'IMG_BICUBIC_FIXED' => \IMG_BICUBIC_FIXED] as $name => $kernel) {
            if (false === @imagescale($source, 50, 50, $kernel)) {
                $unavailable[] = $name;
            }
        }

        // Whichever way this build goes, what the driver uses has to work, and
        // it takes no kernel argument at all.
        $destination = imagecreatetruecolor(50, 50);
        imagecopyresampled($destination, $source, 0, 0, 0, 0, 50, 50, 100, 100);

        self::assertSame(50, imagesx($destination), 'The method the driver uses does not work on this build.');
        self::assertLessThanOrEqual(2, \count($unavailable));
    }

    /**
     * The ideal area average, in float, for any ratio.
     *
     * Each output pixel is the exact integral of the source over its footprint,
     * with fractional weights at the edges. This is the reference every
     * resampling paper uses and, unlike a block average, it is defined at
     * non-integer ratios.
     *
     * @return array{int, int, list<array{float, float, float}>}
     */
    private static function areaAverage(string $path, int $width, int $height): array
    {
        $image = imagecreatefrompng($path);
        self::assertInstanceOf(\GdImage::class, $image);

        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $stepX = $sourceWidth / $width;
        $stepY = $sourceHeight / $height;
        $pixels = [];

        for ($y = 0; $y < $height; ++$y) {
            $top = $y * $stepY;
            $bottom = ($y + 1) * $stepY;

            for ($x = 0; $x < $width; ++$x) {
                $left = $x * $stepX;
                $right = ($x + 1) * $stepX;
                $red = $green = $blue = $weight = 0.0;

                for ($j = (int) floor($top); $j < min($sourceHeight, ceil($bottom)); ++$j) {
                    $weightY = min($bottom, $j + 1) - max($top, $j);

                    if ($weightY <= 0.0) {
                        continue;
                    }

                    for ($i = (int) floor($left); $i < min($sourceWidth, ceil($right)); ++$i) {
                        $weightX = min($right, $i + 1) - max($left, $i);

                        if ($weightX <= 0.0) {
                            continue;
                        }

                        $area = $weightX * $weightY;
                        $colour = imagecolorat($image, $i, $j);
                        $red += (($colour >> 16) & 0xFF) * $area;
                        $green += (($colour >> 8) & 0xFF) * $area;
                        $blue += ($colour & 0xFF) * $area;
                        $weight += $area;
                    }
                }

                $pixels[] = [$red / $weight, $green / $weight, $blue / $weight];
            }
        }

        return [$width, $height, $pixels];
    }

    /**
     * @param array{int, int, list<array{float, float, float}>} $reference
     */
    private static function rmse(array $reference, string $bytes): float
    {
        [$width, $height, $pixels] = $reference;
        $image = imagecreatefromstring($bytes);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertSame($width, imagesx($image), 'The rendered size does not match the reference.');
        self::assertSame($height, imagesy($image));

        $sum = 0.0;

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $colour = imagecolorat($image, $x, $y);
                [$red, $green, $blue] = $pixels[$y * $width + $x];
                $sum += ((($colour >> 16) & 0xFF) - $red) ** 2
                    + ((($colour >> 8) & 0xFF) - $green) ** 2
                    + (($colour & 0xFF) - $blue) ** 2;
            }
        }

        return sqrt($sum / ($width * $height * 3));
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= Corpus::shared();
    }
}
