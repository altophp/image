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
use Alto\Image\Driver\Support;
use Alto\Image\Image;
use Alto\Image\Test\Corpus;
use Alto\Image\Test\ImageAssertions;
use Alto\Image\Transform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GD and Imagick, on the same input, must produce the same picture.
 *
 * Not the same bytes: two encoders that both did the right thing produce
 * different bytes, and the two drivers deliberately downscale with different
 * kernels because different libraries are good at different things. What has to
 * match is what a person sees, and the size, exactly.
 *
 * This is the test that makes the driver seam real rather than aspirational. One
 * driver is an implementation; two that agree is an interface.
 */
final class CrossDriverEquivalenceTest extends TestCase
{
    use ImageAssertions;

    private static ?Corpus $corpus = null;

    protected function setUp(): void
    {
        if (!GdDriver::isAvailable() || !ImagickDriver::isAvailable()) {
            self::markTestSkipped('This one needs both ext-gd and ext-imagick.');
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function transforms(): iterable
    {
        // The tolerance is bits of a 64-bit perceptual hash, and the numbers are
        // measured rather than guessed: every case below currently comes in at
        // ten bits or less across three fixtures, and these leave roughly a
        // factor of two of headroom. Past twenty bits it is a different picture.
        //
        // Geometry is where the two drivers should agree closest, and does. What
        // drifts is the operations the two libraries implement differently:
        // GD desaturates on one luma formula and ImageMagick modulates on
        // another, so grayscale is the widest gap in the set at ten bits.
        yield 'cover' => ['cover=200x120', 8];
        yield 'cover with a corner gravity' => ['cover=160x160,g:top-right', 8];
        yield 'inside' => ['inside=150x150', 10];
        yield 'contain onto white' => ['contain=200x200,bg:ffffff,s:both', 8];
        yield 'fill' => ['fill=200x120,s:both', 8];
        yield 'crop' => ['crop=120x100,x:20,y:10', 4];
        yield 'extend' => ['extend=12,bg:e63946', 4];
        yield 'a quarter turn' => ['inside=200x200|rotate=90', 10];
        yield 'a half turn' => ['inside=200x200|rotate=180', 10];
        yield 'a free angle' => ['inside=200x200|rotate=37,bg:000000', 12];
        yield 'a horizontal flip' => ['inside=200x200|flip=h', 10];
        yield 'a vertical flip' => ['inside=200x200|flip=v', 10];
        yield 'invert' => ['inside=200x200|invert', 10];
        yield 'flatten' => ['inside=200x200|flatten=ffffff', 12];
        yield 'pixelate' => ['inside=200x200|pixelate=8', 12];
        yield 'a trim then a fixed box' => ['trim=6|cover=150x100,s:both', 12];

        // Two different luma formulas, and both are defensible.
        yield 'grayscale' => ['inside=200x200|grayscale', 16];

        // GD approximates these three, and the number is what that costs.
        yield 'blur' => ['inside=200x200|blur=2', 14];
        yield 'sharpen' => ['inside=200x200|sharpen=1', 14];
        yield 'a tone adjustment' => ['inside=200x200|adjust=b:15,s:20', 16];
    }

    /**
     * The size has to be identical, and the picture has to be recognisably the same.
     */
    #[DataProvider('transforms')]
    public function testBothDriversProduceTheSamePicture(string $transform, int $tolerance): void
    {
        $gd = new GdDriver();
        $imagick = new ImagickDriver();
        $parsed = Transform::parse($transform);
        $checked = 0;

        foreach (['photo.png', 'bordered.png', 'portrait.jpg'] as $fixture) {
            $path = self::corpus()->path($fixture);

            $left = $this->render($gd, $path, $parsed);
            $right = $this->render($imagick, $path, $parsed);

            self::assertSame(
                (string) $left->size(),
                (string) $right->size(),
                \sprintf('"%s" on %s came out at two different sizes, which is layout shift.', $transform, $fixture),
            );

            self::assertImageSimilar($left->bytes, $right->bytes, $gd, $tolerance, \sprintf('"%s" on %s', $transform, $fixture));
            ++$checked;
        }

        self::assertSame(3, $checked);
    }

    /**
     * And they agree about what an image is before either of them opens it,
     * because neither of them is what reads the header.
     */
    public function testBothDriversAgreeOnTheProjectionBecauseNeitherOwnsIt(): void
    {
        foreach (self::corpus()->readable() as $label => $path) {
            $transform = Transform::parse('cover=128x96,s:both|rotate=30');

            $gd = Image::open($path)->using(new GdDriver())->transformedBy($transform)->png();
            $imagick = Image::open($path)->using(new ImagickDriver())->transformedBy($transform)->png();

            self::assertSame((string) $gd->size(), (string) $imagick->size(), $label);
        }
    }

    /**
     * Where they differ, they say so. GD approximates a blur and Imagick does
     * not, and both report exactly that.
     */
    public function testTheyDisagreeOutLoudRatherThanSilently(): void
    {
        $gd = $this->render(new GdDriver(), self::corpus()->path('photo.png'), Transform::parse('inside=120x120|blur=3'));
        $imagick = $this->render(new ImagickDriver(), self::corpus()->path('photo.png'), Transform::parse('inside=120x120|blur=3'));

        self::assertNotSame([], $gd->degradations, 'GD blurred by a sigma it cannot honour and said nothing.');
        self::assertSame([], $imagick->degradations, 'Imagick reported a degradation for something it does exactly.');
        self::assertStringContainsString('blur', $gd->degradations[0]);
    }

    /**
     * Each driver advertises its actual capabilities.
     */
    public function testThePairOfCapabilityTablesIsNotIdentical(): void
    {
        $gd = (new GdDriver())->capabilities();
        $imagick = (new ImagickDriver())->capabilities();

        self::assertNotSame($gd->readNames(), $imagick->readNames());
        self::assertSame(Support::No, (new GdDriver())->supports(new \Alto\Image\Operation\IccConvert()));
        self::assertNotSame(Support::No, (new ImagickDriver())->supports(new \Alto\Image\Operation\IccConvert()));
    }

    private function render(DriverInterface $driver, string $path, Transform $transform): \Alto\Image\Result
    {
        return Image::open($path)->using($driver)->transformedBy($transform)->png()->render();
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= Corpus::shared();
    }
}
