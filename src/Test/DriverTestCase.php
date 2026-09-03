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

use Alto\Image\Anchor;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Support;
use Alto\Image\Exception\ImageExceptionInterface;
use Alto\Image\Fit;
use Alto\Image\Focus;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\Limits;
use Alto\Image\MetadataPolicy;
use Alto\Image\Operation\Rotate;
use Alto\Image\Scaling;
use Alto\Image\Size;
use Alto\Image\Source;
use Alto\Image\Transform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reusable PHPUnit conformance suite for image drivers.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract class DriverTestCase extends TestCase
{
    use ImageAssertions;

    private static ?Corpus $corpus = null;

    /**
     * The driver under test.
     */
    abstract protected function driver(): DriverInterface;

    /**
     * Skips the whole case when the driver's extension is not installed.
     */
    protected function setUp(): void
    {
        if (!$this->driver()->capabilities()->isAvailable()) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped(\sprintf('%s is not installed here.', $this->driver()->name()));
            // @codeCoverageIgnoreEnd
        }
    }

    protected static function corpus(): Corpus
    {
        return self::$corpus ??= Corpus::shared();
    }

    // ---------------------------------------------------------------- the claim

    /**
     * Every fixture, every transform: what was projected is what was rendered.
     */
    #[DataProvider('transforms')]
    public function testProjectedOutputMatchesActualOutput(string $transform): void
    {
        $driver = $this->driver();
        $parsed = Transform::parse($transform);
        $checked = 0;

        foreach (self::corpus()->readable() as $label => $path) {
            $image = Image::open($path)->using($driver)->transformedBy($parsed)->png();

            if (Support::No === $driver->canDecode($image->sourceMetadata()->format)) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $projected = $image->size();
            $result = $image->render();

            self::assertSame(
                $projected->width,
                $result->metadata->width(),
                \sprintf('%s width, "%s" on %s', $driver->name(), $transform, $label),
            );
            self::assertSame(
                $projected->height,
                $result->metadata->height(),
                \sprintf('%s height, "%s" on %s', $driver->name(), $transform, $label),
            );

            // Verify the encoded file size independently of projected metadata.
            self::assertImageSize($result->bytes, $projected, \sprintf('"%s" on %s', $transform, $label));

            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'The corpus produced nothing to check.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transforms(): iterable
    {
        foreach ([
            'cover=200x200',
            'cover=200x120,g:top-right',
            'cover=200x120,g:attention',
            'cover=120x200,g:entropy',
            'cover=800x600,s:both',
            'inside=150x150',
            'inside=1000x1000',
            'outside=150x150,s:both',
            'contain=200x200,bg:ffffff,s:both',
            'contain=200x200,s:both',
            'fill=200x120,s:both',
            'crop=100x80',
            'crop=100x80,x:10,y:5',
            'crop=100x80,g:bottom-left',
            'crop=9000x9000',
            'extend=12',
            'extend=0,t:20,r:5,bg:e63946',
            'rotate=90',
            'rotate=180',
            'rotate=270',
            'rotate=37',
            'rotate=1,bg:ffffffff',
            'flip=h',
            'flip=v',
            'orient',
            'flatten=ffffff',
            'grayscale',
            'invert',
            'pixelate=8',
            'blur=2',
            'sharpen=1,a:1.5',
            'adjust=b:10,c:-10,s:25,g:1.1',
            'tint=e63946,o:0.5',
            'inside=200x200|rotate=90|grayscale',
            'cover=160x160|blur=1.5|sharpen=1',
            'rotate=45|cover=100x100,s:both',
            'extend=10,bg:000000|crop=120x120',
        ] as $transform) {
            yield $transform => [$transform];
        }
    }

    // ------------------------------------------------------------- capabilities

    /**
     * A driver reads everything it says it reads.
     */
    public function testReadsEveryFormatItClaims(): void
    {
        $driver = $this->driver();

        foreach ($driver->capabilities()->reads as $format) {
            self::assertTrue(
                $driver->canDecode($format)->isPossible(),
                \sprintf('%s lists %s under reads but canDecode() says No.', $driver->name(), $format->value),
            );
        }
    }

    /**
     * And writes everything it says it writes, for real, by writing one.
     */
    public function testWritesEveryFormatItClaims(): void
    {
        $driver = $this->driver();
        $source = self::corpus()->path('photo.png');

        foreach ($driver->capabilities()->writes as $format) {
            $encoding = new Encoding($format);

            if (Support::No === $driver->canEncode($encoding)) {
                // @codeCoverageIgnoreStart
                self::fail(\sprintf('%s lists %s under writes but canEncode() says No.', $driver->name(), $format->value));
                // @codeCoverageIgnoreEnd
            }

            $result = Image::open($source)->using($driver)->fit(64, 64)->encode($format)->render();

            self::assertGreaterThan(0, $result->length(), \sprintf('%s wrote no bytes for %s.', $driver->name(), $format->value));
            self::assertImageFormat($result->bytes, $format, \sprintf('%s writing %s', $driver->name(), $format->value));
        }
    }

    public function testRefusesFormatsItCannotWrite(): void
    {
        $driver = $this->driver();
        $refused = 0;

        foreach (Format::cases() as $format) {
            if ($driver->capabilities()->canWrite($format)) {
                continue;
            }

            self::assertSame(
                Support::No,
                $driver->canEncode(new Encoding($format)),
                \sprintf('%s does not list %s under writes but canEncode() did not say No.', $driver->name(), $format->value),
            );
            ++$refused;
        }

        self::assertGreaterThanOrEqual(0, $refused);
    }

    /**
     * Concrete capability answers must not be weaker than the advertised floor.
     */
    public function testCapabilityTableAgreesWithTheQuestions(): void
    {
        $driver = $this->driver();

        foreach (Transform::defaults() as $name => $class) {
            $operation = match ($name) {
                'cover', 'contain', 'fill', 'inside', 'outside' => $class::parse([Transform::NAME => $name, '0' => '100x100']),
                'crop' => $class::parse(['0' => '100x100']),
                'overlay' => $class::parse(['0' => rawurlencode(self::corpus()->path('flat.png'))]),
                default => $class::parse([]),
            };

            $floor = $driver->capabilities()->supports($operation);

            self::assertTrue(
                $driver->supports($operation)->isAtLeast($floor),
                \sprintf(
                    '%s lists %s as %s in its capability table and then answered %s for one, which is worse than the table promised.',
                    $driver->name(),
                    $class,
                    $floor->name,
                    $driver->supports($operation)->name,
                ),
            );
        }
    }

    /**
     * Approximate operations must report a degradation.
     */
    public function testApproximationIsReportedRatherThanHidden(): void
    {
        $driver = $this->driver();
        $checked = 0;

        foreach (['blur=3', 'sharpen=2', 'adjust=b:20', 'tint=e63946', 'rotate=17'] as $transform) {
            $parsed = Transform::parse($transform);
            $operation = $parsed->operations[0];

            if (Support::Approximate !== $driver->supports($operation)) {
                continue;
            }

            $result = Image::open(self::corpus()->path('photo.png'))
                ->using($driver)
                ->fit(120, 120)
                ->transformedBy(Transform::parse('inside=120x120|' . $transform))
                ->png()
                ->render();

            self::assertNotSame([], $result->degradations, \sprintf(
                '%s said it only approximates "%s" and then reported nothing.',
                $driver->name(),
                $transform,
            ));
            ++$checked;
        }

        self::assertGreaterThanOrEqual(0, $checked);
    }

    // ------------------------------------------------------------------ batching

    /**
     * One source, N outputs, one call, N results in order.
     */
    public function testProducesEveryOutputInOrderFromOneCall(): void
    {
        $image = Image::open(self::corpus()->path('photo.jpg'))
            ->using($this->driver())
            ->cover(ratio: 16 / 9)
            ->widths(80, 160, 240, 320)
            ->webp();

        $results = $image->render();

        self::assertCount(4, $results);

        foreach ([80, 160, 240, 320] as $index => $width) {
            self::assertSame($width, $results[$index]->size()->width, 'Results came back out of order.');
            self::assertSame((int) round($width * 9 / 16), $results[$index]->size()->height);
        }
    }

    /**
     * A fan-out across formats produces each one.
     */
    public function testFansOutAcrossFormats(): void
    {
        $driver = $this->driver();
        $formats = array_values(array_filter(
            [Format::Png, Format::Jpeg, Format::Webp],
            static fn(Format $f): bool => $driver->capabilities()->canWrite($f),
        ));

        if (\count($formats) < 2) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped('This driver writes fewer than two of png, jpeg and webp.');
            // @codeCoverageIgnoreEnd
        }

        $image = Image::open(self::corpus()->path('photo.png'))->using($driver)->fit(64, 64)->formats(...$formats);

        self::assertCount(\count($formats), $image);

        foreach ($image as $index => $one) {
            self::assertImageFormat($one->render()->bytes, $formats[$index]);
        }
    }

    // ------------------------------------------------------------- doing nothing

    /**
     * An unchanged request copies the source bytes.
     */
    public function testANoopCopiesRatherThanReencodes(): void
    {
        $path = self::corpus()->path('photo.png');
        $original = (string) file_get_contents($path);

        $result = Image::open($path)
            ->using($this->driver())
            ->fit(600, 400)
            ->encode(Format::Png, metadata: MetadataPolicy::Keep)
            ->render();

        self::assertTrue($result->copied, 'A same-size, same-format request should have been recognised as a noop.');
        self::assertSame($original, $result->bytes, 'A noop returned bytes that are not the source bytes.');
    }

    /**
     * Naming a quality is a request to re-compress, whatever the geometry says.
     */
    public function testANamedQualityDefeatsTheNoop(): void
    {
        $driver = $this->driver();

        if (!$driver->capabilities()->canWrite(Format::Jpeg)) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped('This driver does not write jpeg.');
            // @codeCoverageIgnoreEnd
        }

        $result = Image::open(self::corpus()->path('photo.jpg'))
            ->using($driver)
            ->fit(600, 400)
            ->jpeg(40)
            ->render();

        self::assertFalse($result->copied, 'A named quality should have forced a re-encode.');
    }

    // ----------------------------------------------------------------- geometry

    public function testQuarterTurnsExchangeTheAxesExactly(): void
    {
        $driver = $this->driver();

        foreach ([90, 180, 270] as $degrees) {
            $image = Image::open(self::corpus()->path('photo.png'))->using($driver)->fit(200, 200)->rotate($degrees)->png();
            $result = $image->render();
            $expected = (new Rotate($degrees))->boundingBox(new Size(200, 133));

            self::assertTrue(
                $result->size()->equals($expected),
                \sprintf('Rotating by %d gave %s and should have given %s.', $degrees, $result->size(), $expected),
            );
        }
    }

    /**
     * Four quarter turns is where you started.
     */
    public function testFourQuarterTurnsReturnToTheOriginal(): void
    {
        $driver = $this->driver();
        $source = self::corpus()->path('photo.png');

        $straight = Image::open($source)->using($driver)->fit(160, 160)->png()->render();
        $turned = Image::open($source)->using($driver)->fit(160, 160)
            ->rotate(90)->rotate(90)->rotate(90)->rotate(90)->png()->render();

        self::assertImageSimilar($straight->bytes, $turned->bytes, $driver, 4, 'Four quarter turns changed the picture.');
    }

    public function testCropTakesTheRegionItWasAskedFor(): void
    {
        $driver = $this->driver();
        $source = self::corpus()->path('bordered.png');

        $left = Image::open($source)->using($driver)->crop(100, 100, Anchor::TopLeft)->png()->render();
        $right = Image::open($source)->using($driver)->crop(100, 100, Anchor::BottomRight)->png()->render();

        self::assertImageSize($left->bytes, new Size(100, 100));
        self::assertImageSize($right->bytes, new Size(100, 100));
        self::assertImageDiffers($left->bytes, $right->bytes, $driver, 6, 'Two opposite corners produced the same crop.');
    }

    public function testContentAwareCropKeepsTheSizeItPromised(): void
    {
        $driver = $this->driver();

        foreach ([Focus::Attention, Focus::Entropy] as $focus) {
            $result = Image::open(self::corpus()->path('bordered.png'))
                ->using($driver)
                ->cover(120, 120, gravity: $focus, scaling: Scaling::Both)
                ->png()
                ->render();

            self::assertImageSize($result->bytes, new Size(120, 120), \sprintf('%s did not hold its size.', $focus->value));
        }
    }

    public function testTrimRemovesTheBorderAndNothingElse(): void
    {
        $result = Image::open(self::corpus()->path('bordered.png'))
            ->using($this->driver())
            ->trim(4)
            ->png()
            ->render();

        // The picture inside the white border runs from (40, 30) to (259, 169).
        self::assertSame(220, $result->size()->width, 'Trim did not find the left and right edges.');
        self::assertSame(140, $result->size()->height, 'Trim did not find the top and bottom edges.');
    }

    public function testExtendAddsExactlyWhatItWasAskedFor(): void
    {
        $result = Image::open(self::corpus()->path('flat.png'))
            ->using($this->driver())
            ->extend(10, 20, 30, 40, '#000000')
            ->png()
            ->render();

        self::assertSame(320 + 20 + 40, $result->size()->width);
        self::assertSame(240 + 10 + 30, $result->size()->height);
    }

    // ---------------------------------------------------------------- resampling

    /**
     * Verifies that downscaling does not introduce visible aliasing.
     */
    #[DataProvider('reductions')]
    public function testDownscalingDoesNotAlias(int $target, int $maxSpread): void
    {
        $result = Image::open(self::corpus()->path('checkerboard.png'))
            ->using($this->driver())
            ->fit($target, $target)
            ->png()
            ->render();

        self::assertNoMoire($result->bytes, $this->driver(), $maxSpread, \sprintf(
            '%s reducing 1024 to %d, a factor of %.1f',
            $this->driver()->name(),
            $target,
            1024 / $target,
        ));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function reductions(): iterable
    {
        yield '1.5x' => [683, 96];
        yield '2.2x' => [465, 64];
        yield '3.3x' => [310, 48];
        yield '5.0x' => [205, 40];
        yield '10x' => [102, 32];
    }

    /**
     * A hard edge is representable at any scale, so no correct kernel softens it
     * into grey. This is the other half of the antialiasing argument: removing
     * frequencies that cannot be represented is not the same as losing detail.
     */
    public function testAHardEdgeSurvivesDownscaling(): void
    {
        $result = Image::open(self::corpus()->path('edge.png'))
            ->using($this->driver())
            ->fit(128, 64)
            ->png()
            ->render();

        $raster = self::raster($result->bytes, $this->driver());
        $left = $raster->luma(2, (int) ($raster->height / 2));
        $right = $raster->luma($raster->width - 3, (int) ($raster->height / 2));

        self::assertLessThan(24.0, $left, 'The black half of a hard edge did not stay black.');
        self::assertGreaterThan(230.0, $right, 'The white half of a hard edge did not stay white.');
    }

    public function testAFlatColourStaysFlat(): void
    {
        $result = Image::open(self::corpus()->path('flat.png'))
            ->using($this->driver())
            ->fit(80, 80)
            ->png()
            ->render();

        self::assertImageIsFlat($result->bytes, $this->driver(), 6);
    }

    // --------------------------------------------------------------------- alpha

    public function testTransparencySurvivesAFormatThatHasIt(): void
    {
        $driver = $this->driver();

        if (!$driver->capabilities()->canWrite(Format::Png)) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped('This driver does not write png.');
            // @codeCoverageIgnoreEnd
        }

        $result = Image::open(self::corpus()->path('alpha.png'))
            ->using($driver)
            ->fit(64, 64)
            ->png()
            ->render();

        self::assertTrue($result->metadata->hasAlpha, 'The projection lost the alpha channel.');

        $reprobed = Source::bytes($result->bytes)->metadata();
        self::assertTrue($reprobed->hasAlpha, 'The encoder wrote a file with no alpha channel.');
    }

    public function testTransparencyBecomesABackgroundInAFormatWithout(): void
    {
        $driver = $this->driver();

        if (!$driver->capabilities()->canWrite(Format::Jpeg)) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped('This driver does not write jpeg.');
            // @codeCoverageIgnoreEnd
        }

        $result = Image::open(self::corpus()->path('alpha.png'))
            ->using($driver)
            ->fit(64, 64)
            ->jpeg(90)
            ->render();

        self::assertFalse($result->metadata->hasAlpha);
        self::assertImageFormat($result->bytes, Format::Jpeg);
    }

    // --------------------------------------------------------------- orientation

    /**
     * All eight EXIF orientations display as the same picture.
     */
    public function testEveryExifOrientationDisplaysTheSameWay(): void
    {
        $driver = $this->driver();
        $reference = null;

        foreach (self::corpus()->orientations() as $orientation => $path) {
            $image = Image::open($path)->using($driver)->fit(96, 96)->png();

            self::assertSame(
                '96x72',
                (string) $image->size(),
                \sprintf('Orientation %d projected the wrong shape, so the tag was not read.', $orientation),
            );

            $bytes = $image->render()->bytes;
            $reference ??= $bytes;

            self::assertImageSimilar($reference, $bytes, $driver, 8, \sprintf(
                'Orientation %d does not display like orientation 1.',
                $orientation,
            ));
        }
    }

    // -------------------------------------------------------------------- limits

    /**
     * A decompression bomb is refused from its header, before any decoder sees it.
     */
    public function testAPixelBombIsRefusedFromItsHeader(): void
    {
        $this->expectException(ImageExceptionInterface::class);

        Image::open(self::corpus()->path('bombs/dimensions.png'))
            ->using($this->driver())
            ->within(new Limits(maxPixels: 50_000_000))
            ->fit(100, 100)
            ->png()
            ->render();
    }

    /**
     * Malformed input throws something catchable. It never fatals, and it never
     * silently produces a picture of nothing.
     */
    #[DataProvider('hostileFixtures')]
    public function testHostileInputThrowsRatherThanFatals(string $label): void
    {
        $paths = self::corpus()->hostile();

        try {
            Image::open($paths[$label])->using($this->driver())->fit(64, 64)->png()->render();
            // @codeCoverageIgnoreStart
            self::fail(\sprintf('"%s" produced an image instead of an exception.', $label));
            // @codeCoverageIgnoreEnd
        } catch (ImageExceptionInterface $expected) {
            self::assertNotSame('', $expected->getMessage(), 'An exception with no message is not a fix.');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileFixtures(): iterable
    {
        foreach (array_keys(self::corpus()->hostile()) as $label) {
            yield $label => [$label];
        }
    }

    // ------------------------------------------------------------------ encoding

    public function testQualityChangesTheByteCount(): void
    {
        $driver = $this->driver();

        if (!$driver->capabilities()->canWrite(Format::Jpeg)) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped('This driver does not write jpeg.');
            // @codeCoverageIgnoreEnd
        }

        $low = Image::open(self::corpus()->path('photo.png'))->using($driver)->fit(240, 240)->jpeg(30)->render();
        $high = Image::open(self::corpus()->path('photo.png'))->using($driver)->fit(240, 240)->jpeg(95)->render();

        self::assertLessThan($high->length(), $low->length(), 'Quality 30 was not smaller than quality 95.');
        self::assertImageSimilar($low->bytes, $high->bytes, $driver, 10, 'Quality 30 is not the same picture as quality 95.');
    }

    public function testAByteCeilingIsRespectedWhenItCanBe(): void
    {
        $driver = $this->driver();

        if (!$driver->capabilities()->canWrite(Format::Jpeg)) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped('This driver does not write jpeg.');
            // @codeCoverageIgnoreEnd
        }

        $unbounded = Image::open(self::corpus()->path('photo.png'))->using($driver)->fit(300, 300)->jpeg(95)->render();
        $ceiling = intdiv($unbounded->length(), 3);

        $bounded = Image::open(self::corpus()->path('photo.png'))
            ->using($driver)
            ->fit(300, 300)
            ->encode(Format::Jpeg, quality: 95, maxBytes: $ceiling)
            ->render();

        self::assertLessThanOrEqual($ceiling, $bounded->length(), \sprintf(
            'Asked for at most %d bytes and got %d, with no degradation reported.',
            $ceiling,
            $bounded->length(),
        ));
    }

    public function testAnImpossibleByteCeilingIsReportedRatherThanMissedSilently(): void
    {
        $driver = $this->driver();

        if (!$driver->capabilities()->canWrite(Format::Jpeg)) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped('This driver does not write jpeg.');
            // @codeCoverageIgnoreEnd
        }

        $result = Image::open(self::corpus()->path('photo.png'))
            ->using($driver)
            ->fit(400, 400)
            ->encode(Format::Jpeg, maxBytes: 64)
            ->render();

        self::assertNotSame([], $result->degradations, 'A ceiling that could not be met was not reported.');
    }

    // -------------------------------------------------------------- the metadata

    /**
     * Stripping metadata really strips it.
     */
    public function testStrippingMetadataRemovesIt(): void
    {
        $driver = $this->driver();

        if (!$driver->capabilities()->canWrite(Format::Jpeg)) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped('This driver does not write jpeg.');
            // @codeCoverageIgnoreEnd
        }

        $result = Image::open(self::corpus()->path('orientation/6.jpg'))
            ->using($driver)
            ->fit(64, 64)
            ->encode(Format::Jpeg, metadata: MetadataPolicy::Strip)
            ->render();

        $reprobed = Source::bytes($result->bytes)->metadata();

        self::assertSame(1, $reprobed->orientation, 'The orientation tag survived a strip, so the pixels will turn twice.');
        self::assertFalse($reprobed->hasMetadata, 'Something is still in there after a strip.');
    }

    // -------------------------------------------------------------------- escape

    public function testTheEscapeHatchReceivesTheNativeHandle(): void
    {
        $driver = $this->driver();

        if (Support::No === $driver->supports(new \Alto\Image\Operation\Escape(static fn(mixed $h): mixed => $h))) {
            // @codeCoverageIgnoreStart
            self::markTestSkipped(\sprintf('%s has no native handle to escape to.', $driver->name()));
            // @codeCoverageIgnoreEnd
        }

        $seen = null;

        $result = Image::open(self::corpus()->path('photo.png'))
            ->using($driver)
            ->fit(64, 64)
            ->escape(static function (mixed $handle) use (&$seen): mixed {
                $seen = get_debug_type($handle);

                return $handle;
            })
            ->png()
            ->render();

        self::assertNotNull($seen, 'The escape closure was never called.');
        self::assertGreaterThan(0, $result->length());
    }

    // ---------------------------------------------------------------- projection

    /**
     * Fit never enlarges under the default policy, whatever box it is given.
     */
    public function testTheDefaultScalingPolicyNeverEnlarges(): void
    {
        $result = Image::open(self::corpus()->path('flat.png'))
            ->using($this->driver())
            ->fit(4000, 4000)
            ->png()
            ->render();

        self::assertSame(320, $result->size()->width, 'The default scaling policy enlarged a source.');
        self::assertSame(240, $result->size()->height);
    }

    public function testEveryRungOfALadderKeepsTheSameRatio(): void
    {
        $image = Image::open(self::corpus()->path('photo.jpg'))
            ->using($this->driver())
            ->cover(ratio: 16 / 9, scaling: Scaling::Both)
            ->widths(64, 128, 256)
            ->png();

        $ratios = [];

        foreach ($image as $one) {
            $size = $one->render()->size();
            $ratios[] = round($size->ratio(), 3);
        }

        self::assertCount(1, array_unique($ratios), 'The rungs of one ladder came out at different shapes.');
    }

    public function testAnExactFitProducesTheBoxExactly(): void
    {
        foreach ([Fit::Cover, Fit::Contain, Fit::Fill] as $fit) {
            $result = Image::open(self::corpus()->path('photo.jpg'))
                ->using($this->driver())
                ->resize(137, 91, $fit, scaling: Scaling::Both)
                ->png()
                ->render();

            self::assertSame('137x91', (string) $result->size(), \sprintf('%s did not fill the box.', $fit->value));
        }
    }
}
