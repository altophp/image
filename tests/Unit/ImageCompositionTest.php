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

use Alto\Image\Driver\Plan;
use Alto\Image\Exception\UnmeasurableException;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\ImageSet;
use Alto\Image\Internal\AbstractImage;
use Alto\Image\Source;
use Alto\Image\Store\LocalStore;
use Alto\Image\Test\ArrayDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Image is singular. ImageSet holds several outputs of that same source.
 *
 * Every assertion here runs with no image extension installed, because nothing
 * decodes until a terminal is reached and the terminal here is a fake.
 */
#[CoversClass(Image::class)]
#[CoversClass(AbstractImage::class)]
#[CoversClass(Plan::class)]
#[CoversClass(ImageSet::class)]
final class ImageCompositionTest extends TestCase
{
    private const string PNG = "\x89PNG\x0D\x0A\x1A\x0A";

    public function testTheOrdinaryCaseIsOneSourceAndOneOutput(): void
    {
        $image = Image::open($this->source())->cover(800, 450)->webp(80);

        self::assertSame('800x450', (string) $image->size());
        self::assertSame('1200x800', (string) $image->sourceSize());
        self::assertSame('cover=800x450', (string) $image->transform());
        self::assertSame(Format::Webp, $image->metadata()->format);
    }

    public function testWidthsMultipliesTheRequestedOutputs(): void
    {
        $images = Image::open($this->source())->cover(ratio: 16 / 9)->widths(640, 960, 1280, 1920)->webp();

        self::assertInstanceOf(ImageSet::class, $images);
        self::assertCount(4, $images);
        self::assertSame(
            ['640x360', '960x540', '1280x720', '1920x1080'],
            array_map(static fn(Image $one): string => (string) $one->transform()->resize()?->width . 'x' . $one->transform()->resize()?->height, $images->images()),
        );
    }

    public function testImageSetsRejectDifferentSources(): void
    {
        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('same source');

        ImageSet::of(
            Image::open(Source::bytes($this->png(1200, 800, 'a'), 'a'))->cover(400, 400),
            Image::open(Source::bytes($this->png(1200, 800, 'b'), 'b'))->cover(800, 800),
        );
    }

    public function testImageSetsCompareSourceContentRatherThanItsDisplayName(): void
    {
        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('same source');

        ImageSet::of(
            Image::open(Source::bytes($this->png(1200, 800, 'first'), 'same-name')),
            Image::open(Source::bytes($this->png(1200, 800, 'second'), 'same-name')),
        );
    }

    public function testImageSetsRejectDifferentRuntimePolicies(): void
    {
        $source = $this->source();

        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('same driver and limits');

        Image::open($source)->using(new ArrayDriver())->cover(400, 400)
            ->and(Image::open($source)->using(new ArrayDriver())->cover(800, 800));
    }

    public function testCombiningDifferentSourcesThroughAndIsRejected(): void
    {
        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('same source');

        Image::open(Source::bytes($this->png(10, 10, 'one')))
            ->and(Image::open(Source::bytes($this->png(10, 10, 'two'))));
    }

    public function testImageSetFactoryRejectsDifferentDrivers(): void
    {
        $source = $this->source();

        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('same driver and limits');

        ImageSet::of(
            Image::open($source)->using(new ArrayDriver()),
            Image::open($source)->using(new ArrayDriver()),
        );
    }

    public function testImageSetsDecomposeIntoSingleOutputImages(): void
    {
        $hero = Image::open($this->source())->cover(ratio: 16 / 9)->widths(640, 960, 1280)->webp();

        foreach ($hero as $one) {
            self::assertInstanceOf(Image::class, $one);
            self::assertSame(round(16 / 9, 3), round($one->size()->ratio(), 3));
        }
    }

    public function testImageSetsCanCombineSelectAndDescribeCompatibleOutputs(): void
    {
        $source = $this->source();
        $driver = new ArrayDriver();
        $images = ImageSet::of(
            Image::open($source)->using($driver)->cover(320, 180)->webp(),
            Image::open($source)->using($driver)->cover(640, 360)->webp(),
        );

        self::assertCount(2, $images);
        self::assertSame(['640x360'], array_map(static fn(Image $image): string => (string) $image->size(), $images->select(1)->images()));
        self::assertSame('2 images of photo (in memory)', (string) $images);
    }

    public function testSelectingNoImagesIsRejected(): void
    {
        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one index');

        Image::open($this->source())->widths(320, 640)->select();
    }

    public function testSelectingAnUnknownImageIsRejected(): void
    {
        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('index 9 does not exist');

        Image::open($this->source())->widths(320, 640)->select(9);
    }

    public function testImageSetsDoNotExposeSingularTerminals(): void
    {
        foreach (['save', 'size', 'bytes', 'signature', 'dataUri'] as $method) {
            self::assertFalse(method_exists(ImageSet::class, $method));
        }
    }

    public function testCardinalityIsExplicitInThePublicTypes(): void
    {
        self::assertNotContains(\Countable::class, class_implements(Image::class));
        self::assertContains(\Countable::class, class_implements(ImageSet::class));

        $type = (new \ReflectionMethod(Image::class, 'widths'))->getReturnType();

        if (!$type instanceof \ReflectionNamedType) {
            self::fail('Image::widths() must have one named return type.');
        }

        self::assertSame(ImageSet::class, $type->getName());
    }

    public function testShapingVerbsAllComposeDownToOnePrimitive(): void
    {
        $source = $this->source();

        self::assertSame('cover=800x450', (string) Image::open($source)->cover(800, 450)->transform());
        self::assertSame('contain=800x450,bg:ffffff', (string) Image::open($source)->contain(800, 450, '#ffffff')->transform());
        self::assertSame('inside=1200x1200', (string) Image::open($source)->fit(1200, 1200)->transform());
        self::assertSame('inside=800x', (string) Image::open($source)->scale(width: 800)->transform());
        self::assertSame('fill=800x450', (string) Image::open($source)->stretch(800, 450)->transform());
    }

    public function testScaleRefusesTwoAxes(): void
    {
        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('takes one axis');

        Image::open($this->source())->scale(320, 180);
    }

    public function testTheRemainingFluentOperationsStayImmutableAndOrdered(): void
    {
        $base = Image::open($this->source());
        $changed = $base
            ->flipHorizontal()
            ->flipVertical()
            ->orient()
            ->flatten('#fff')
            ->overlay('mark.png')
            ->sharpen()
            ->adjust()
            ->grayscale()
            ->invert()
            ->pixelate()
            ->tint('#123456')
            ->convertColourProfile()
            ->keepMetadata();

        self::assertSame('', (string) $base->transform());
        self::assertSame(
            'flip=h|flip=v|orient|flatten=ffffff|overlay=mark.png|sharpen=1|adjust|grayscale|invert|pixelate=8|tint=123456|icc=srgb',
            (string) $changed->transform(),
        );
        self::assertSame(Format::Png, $changed->metadata()->format);
    }

    public function testKeepingOnlyTheColourProfileIsAFirstClassChoice(): void
    {
        $image = Image::open($this->source());

        self::assertSame(
            $image->withMetadata(\Alto\Image\MetadataPolicy::ColourProfile)->signature(),
            $image->keepColourProfile()->signature(),
        );
        self::assertNotSame($image->signature(), $image->keepColourProfile()->signature());
    }

    public function testHeightsFanOutAndCompatibleRequestsCombine(): void
    {
        $source = $this->source();
        $driver = new ArrayDriver();
        $images = Image::open($source)->using($driver)->heights(120, 240)
            ->and(Image::open($source)->using($driver)->widths(320, 640));

        self::assertCount(4, $images);
        self::assertSame(['inside=x120', 'inside=x240', 'inside=320x', 'inside=640x'], array_map(
            static fn(Image $image): string => (string) $image->transform()->resize(),
            $images->images(),
        ));
    }

    public function testAnEmptyFanOutIsRefused(): void
    {
        $this->expectException(\Alto\Image\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one value');

        Image::open($this->source())->heights();
    }

    public function testSingularTerminalsDelegateAndDescribeTheirResult(): void
    {
        $directory = sys_get_temp_dir() . '/alto-image-terminal-' . bin2hex(random_bytes(6));
        $saved = $directory . '/saved.webp';
        $store = new LocalStore($directory . '/store');
        $image = Image::open($this->source())->using(new ArrayDriver())->webp();

        try {
            self::assertSame('photo', $image->name());
            self::assertSame($this->source()->signature(), $image->source()->signature());
            self::assertStringStartsWith('alto:fake', $image->bytes());
            self::assertStringStartsWith('data:image/webp;base64,', $image->dataUri());
            self::assertSame($saved, $image->save($saved)->path);
            self::assertFileExists($saved);
            self::assertNotNull($image->store($store)->path);
            self::assertCount(2, Image::open($this->source())->using(new ArrayDriver())->widths(20, 40)->store($store));
            self::assertStringContainsString('photo (in memory) -> as-is webp q80', (string) $image);
        } finally {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $entry) {
                if (!$entry instanceof \SplFileInfo) {
                    continue;
                }

                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }

            @rmdir($directory);
        }
    }

    public function testTheDriverIsChosenPerCallRatherThanThroughAContainer(): void
    {
        $driver = new ArrayDriver();

        Image::open($this->source())->using($driver)->cover(800, 450)->webp()->render();

        self::assertSame([['source' => 'photo (in memory)', 'spec' => 'cover=800x450 webp q80', 'output' => '800x450']], $driver->calls());
    }

    public function testAFanOutReachesTheDriverAsOneGroupPerSource(): void
    {
        $driver = new ArrayDriver();
        $image = Image::open($this->source())->using($driver)->cover(ratio: 16 / 9)->widths(320, 640)->webp();

        $image->render();

        self::assertSame(['320x180', '640x360'], $driver->outputs());
        self::assertSame(1, $driver->batches(), 'The ImageSet reached the driver in more than one batch.');
    }

    public function testAnImageHoldsNoPixelsUntilATerminalIsReached(): void
    {
        $driver = new ArrayDriver();

        $image = Image::open($this->source())
            ->using($driver)
            ->cover(1280, 720)
            ->blur(2)
            ->avif();

        // 1200x675 rather than 1280x720, because the source is 1200 wide and the
        // default scaling policy does not enlarge. Asking for a box you cannot
        // have gets you the same shape at the size you can.
        self::assertSame('1200x675', (string) $image->size());
        self::assertSame('cover=1280x720|blur=2', (string) $image->transform());
        self::assertSame([], $driver->calls(), 'Something decoded before a terminal was reached.');
    }

    public function testTheSignatureIsStableAndCoversTheWholeRequest(): void
    {
        $source = $this->source();
        $base = Image::open($source)->cover(800, 450)->webp(80);

        self::assertSame($base->signature(), Image::open($source)->cover(800, 450)->webp(80)->signature());
        self::assertNotSame($base->signature(), Image::open($source)->cover(800, 451)->webp(80)->signature());
        self::assertNotSame($base->signature(), Image::open($source)->cover(800, 450)->webp(81)->signature());
        self::assertNotSame($base->signature(), Image::open($source)->cover(800, 450)->avif(80)->signature());
        self::assertNotSame(
            $base->signature(),
            Image::open(Source::bytes($this->png(1200, 800, 'a different upload'), 'other'))->cover(800, 450)->webp(80)->signature(),
        );
    }

    public function testOneExtendValueMeansAllFourSidesEverywhere(): void
    {
        $image = Image::open($this->source())->extend(12);

        self::assertSame('extend=12', (string) $image->transform());
        self::assertSame('1224x824', (string) $image->size());
    }

    public function testDeterministicUnmeasurableOperationsRemainSignable(): void
    {
        self::assertNotSame('', Image::open($this->source())->trim()->signature());
    }

    /**
     * A source made of bytes hashes its bytes, so two identical uploads under
     * different names share a derivative rather than duplicating one.
     */
    public function testTwoIdenticalSourcesShareASignature(): void
    {
        $bytes = $this->png(1200, 800);

        self::assertSame(
            Image::open(Source::bytes($bytes, 'left'))->cover(400, 400)->webp()->signature(),
            Image::open(Source::bytes($bytes, 'right'))->cover(400, 400)->webp()->signature(),
        );
    }

    public function testAnImageHoldingAnEscapeRefusesToMeasureItself(): void
    {
        $image = Image::open($this->source())->cover(800, 450)->escape(static fn(mixed $h): mixed => $h, 'duotone')->webp();

        try {
            $image->size();
            self::fail('An Image holding an Escape should not be measurable.');
        } catch (UnmeasurableException $refusal) {
            self::assertStringContainsString('reads the header and never decodes', $refusal->getMessage());
            self::assertStringContainsString('runs arbitrary code on the raw handle', $refusal->getMessage());
            self::assertStringContainsString('re-open the result', $refusal->getMessage(), 'The message must say what to do instead.');
        }
    }

    public function testAnImageHoldingATrimSaysWhatWouldMakeItMeasurableAgain(): void
    {
        $image = Image::open($this->source())->trim()->webp();

        try {
            $image->size();
            self::fail('A lone trim should not be measurable.');
        } catch (UnmeasurableException $refusal) {
            self::assertStringContainsString('starts with a trim', $refusal->getMessage());
            self::assertStringContainsString('s:both', $refusal->getMessage(), 'The message must say what would fix it.');
        }
    }

    public function testATrimFollowedByAFixedBoxIsMeasurableAgain(): void
    {
        $image = Image::open($this->source())->trim()->cover(800, 450, scaling: \Alto\Image\Scaling::Both)->webp();

        self::assertSame('800x450', (string) $image->size());
    }

    private function source(string $name = 'photo'): Source
    {
        return Source::bytes($this->png(1200, 800), $name);
    }

    /**
     * A PNG signature, an IHDR and an IEND, which is everything a header read
     * needs and nothing a decoder could use. That is the point: every assertion
     * in this file runs with no image extension installed.
     *
     * The IEND matters. Without it the file does not end the way a PNG ends, and
     * Limits::$failOn refuses it as truncated before any driver is chosen, which
     * is exactly what it is there to do.
     */
    private function png(int $width, int $height, string $salt = ''): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";
        $text = '' === $salt ? '' : pack('N', \strlen($salt)) . 'tEXt' . $salt . pack('N', crc32('tEXt' . $salt));

        return self::PNG
            . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . $text
            . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82";
    }
}
