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

use Alto\Image\Colour;
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Driver\Imagick\ImagickPipeline;
use Alto\Image\Driver\Support;
use Alto\Image\Effort;
use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Exception\DriverException;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\MetadataPolicy;
use Alto\Image\Operation\Adjust;
use Alto\Image\Operation\Escape;
use Alto\Image\Operation\IccConvert;
use Alto\Image\Operation\Overlay;
use Alto\Image\Operation\Placement;
use Alto\Image\Operation\Rotate;
use Alto\Image\Operation\Tint;
use Alto\Image\Operation\Trim;
use Alto\Image\Size;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImagickDriver::class)]
#[CoversClass(ImagickPipeline::class)]
final class ImagickDriverTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        if (!ImagickDriver::isAvailable()) {
            self::markTestSkipped('ext-imagick is not installed.');
        }

        $this->directory = sys_get_temp_dir() . '/alto-imagick-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testCapabilitiesDescribeApproximateEncodingOptions(): void
    {
        $driver = new ImagickDriver();

        self::assertSame(Support::Approximate, $driver->canEncode(new Encoding(Format::Jpeg, effort: Effort::Best)));

        $avif = $driver->canEncode(new Encoding(Format::Avif));
        self::assertSame(
            Support::No === $avif ? Support::No : Support::Approximate,
            $driver->canEncode(new Encoding(Format::Avif, effort: Effort::Fast)),
        );
    }

    public function testPlacementConformAndNoopOperationsAreExplicit(): void
    {
        $pipeline = new ImagickPipeline();
        $image = $this->image(4, 3);

        self::assertSame($image, $pipeline->place($image, null, Colour::TRANSPARENT, null));
        self::assertSame($image, $pipeline->conform($image, new Size(4, 3), Colour::TRANSPARENT));
        self::assertSame('6x2', (string) $pipeline->size($pipeline->conform(clone $image, new Size(6, 2), Colour::TRANSPARENT)));

        [$unchanged, $notes] = $pipeline->run(clone $image, [new Rotate(0), new Adjust()]);
        self::assertSame('4x3', (string) $pipeline->size($unchanged));
        self::assertSame([], $notes);

        $placement = new Placement(4, 3, 4, 3);
        self::assertSame('4x3', (string) $pipeline->size($pipeline->place(clone $image, $placement, Colour::TRANSPARENT, null)));
    }

    public function testOverlayCompositesAndNamesReadFailures(): void
    {
        $pipeline = new ImagickPipeline();
        $mark = $this->image(2, 2, 'red');
        $path = $this->directory . '/mark.png';
        $mark->writeImage($path);

        [$merged] = $pipeline->run($this->image(8, 8), [new Overlay($path, opacity: 0.5, margin: 1)]);
        self::assertSame('8x8', (string) $pipeline->size($merged));

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('reading the overlay');
        $pipeline->run($merged, [new Overlay($this->directory . '/missing.png')]);
    }

    public function testIccNamesAndInvalidEscapeHaveDefinedOutcomes(): void
    {
        $pipeline = new ImagickPipeline();
        [$converted, $notes] = $pipeline->run($this->image(2, 2), [new IccConvert('srgb'), new IccConvert('gray'), new IccConvert('cmyk')]);

        self::assertSame([], $notes);
        self::assertSame(\Imagick::COLORSPACE_CMYK, $converted->getImageColorspace());

        try {
            $pipeline->run($converted, [new IccConvert($this->directory . '/missing.icc')]);
            self::fail('A missing ICC profile was accepted.');
        } catch (DriverException $error) {
            self::assertStringContainsString('neither a known name nor a readable file', $error->getMessage());
        }

        $invalidProfile = $this->directory . '/invalid.icc';
        file_put_contents($invalidProfile, 'not an ICC profile');
        [$profiled, $profileNotes] = $pipeline->run($converted, [new IccConvert($invalidProfile)]);
        self::assertSame('2x2', (string) $pipeline->size($profiled));
        self::assertNotSame([], $profileNotes);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('returned string');
        $pipeline->run($converted, [new Escape(static fn(mixed $handle): string => 'wrong', 'bad')]);
    }

    public function testFileIccProfilesAndMetadataPoliciesUseTheirDedicatedPaths(): void
    {
        $profile = $this->minimalIccProfile();
        $path = $this->directory . '/minimal.icc';
        file_put_contents($path, $profile);

        [$converted, $notes] = (new ImagickPipeline())->run($this->image(2, 2), [new IccConvert($path)]);
        self::assertSame([], $notes);
        self::assertNotSame('', $converted->getImageProfile('icc'));

        $driver = new ImagickDriver();
        $policy = new \ReflectionMethod(ImagickDriver::class, 'applyMetadataPolicy');

        $profiled = $this->image(2, 2);
        $profiled->setImageProfile('icc', $profile);
        $policy->invoke($driver, $profiled, MetadataPolicy::ColourProfile);
        self::assertSame($profile, $profiled->getImageProfile('icc'));

        $withoutProfile = $this->image(2, 2);
        $policy->invoke($driver, $withoutProfile, MetadataPolicy::ColourProfile);
        self::assertSame([], $withoutProfile->getImageProfiles('icc', true));

        $copyrighted = $this->image(2, 2);
        $copyrighted->setImageProperty('exif:Artist', 'Simon Andre');
        $copyrighted->setImageProperty('comment', 'private');
        $policy->invoke($driver, $copyrighted, MetadataPolicy::Copyright);
        self::assertSame('Simon Andre', $copyrighted->getImageProperty('exif:Artist'));
        self::assertFalse($copyrighted->getImageProperty('comment'));
    }

    public function testTooSmallDetectsAnInsufficientDecodeHint(): void
    {
        self::assertTrue((new \ReflectionMethod(ImagickDriver::class, 'tooSmall'))->invoke(
            new ImagickDriver(),
            $this->image(2, 2),
            [new Placement(3, 3)],
        ));
    }

    public function testTrimCanUseAnExplicitBackground(): void
    {
        [$trimmed] = (new ImagickPipeline())->run($this->image(3, 3), [new Trim(background: 0xFFFFFFFF)]);

        self::assertSame(1, $trimmed->getImageWidth());
        self::assertSame(1, $trimmed->getImageHeight());
    }

    public function testTintPreservesLuminance(): void
    {
        $image = new \Imagick();
        $image->newPseudoImage(2, 1, 'gradient:black-white');
        $image->setImageFormat('png');

        [$tinted] = (new ImagickPipeline())->run($image, [new Tint(0xFF00B7FF)]);

        self::assertNotSame(
            $tinted->getImagePixelColor(0, 0)->getColor(),
            $tinted->getImagePixelColor(1, 0)->getColor(),
        );
    }

    public function testApproximateEncodingIsReportedByARealRender(): void
    {
        $result = Image::open(Source::bytes($this->image(4, 3)->getImagesBlob()))
            ->using(new ImagickDriver())
            ->encode(Format::Jpeg, effort: Effort::Best)
            ->render();

        self::assertSame(Format::Jpeg, $result->format());
        self::assertFalse($result->isExact());
        self::assertStringContainsString('could not honour every encoding option', implode(' ', $result->degradations));
    }

    public function testKeepMetadataAndAnimationDecodeUseTheirDedicatedPaths(): void
    {
        $driver = new ImagickDriver();
        $source = $this->image(4, 3)->getImagesBlob();
        $kept = Image::open(Source::bytes($source))->using($driver)->blur()->encode(Format::Png, metadata: MetadataPolicy::Keep)->render();
        self::assertSame(Format::Png, $kept->format());

        $animation = new \Imagick();

        foreach (['red', 'blue'] as $colour) {
            $frame = $this->image(4, 3, $colour);
            $frame->setImageFormat('gif');
            $animation->addImage($frame);
        }

        $animation->setImageFormat('gif');
        $first = Image::open(Source::bytes($animation->getImagesBlob()))
            ->using($driver)
            ->blur()
            ->png()
            ->render();

        self::assertSame(Format::Png, $first->format());
        self::assertSame('4x3', (string) $first->size());
    }

    public function testAValidHeaderWithInvalidPixelsIsRejected(): void
    {
        $this->expectException(CorruptImageException::class);

        Image::open(Source::bytes($this->pngHeader(20, 10)))
            ->using(new ImagickDriver())
            ->blur()
            ->png()
            ->bytes();
    }

    public function testAMissingDecoderNamesTheCoderAndDoctor(): void
    {
        $writable = new \ReflectionProperty(ImagickDriver::class, 'writable');
        $known = new \ReflectionProperty(ImagickDriver::class, 'known');
        $previousWritable = $writable->getValue();
        $previousKnown = $known->getValue();

        $writable->setValue(null, ['AVIF' => false, 'PNG' => true]);
        $known->setValue(null, []);

        $source = pack('N', 24) . 'ftypavif' . pack('N', 0) . 'avifmif1'
            . pack('N', 20) . 'ispe' . "\x00\x00\x00\x00" . pack('NN', 20, 10);

        try {
            Image::open(Source::bytes($source))
                ->using(new ImagickDriver())
                ->blur()
                ->png()
                ->bytes();
            self::fail('A missing AVIF decoder was reported as corrupt input.');
        } catch (DriverException $error) {
            self::assertStringContainsString('no coder for avif', $error->getMessage());
            self::assertStringContainsString('image doctor', $error->getMessage());
        } finally {
            $writable->setValue(null, $previousWritable);
            $known->setValue(null, $previousKnown);
        }
    }

    private function image(int $width, int $height, string $colour = 'white'): \Imagick
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel($colour));
        $image->setImageFormat('png');

        return $image;
    }

    private function minimalIccProfile(): string
    {
        return pack('N', 132)
            . str_repeat("\0", 4)
            . pack('N', 0x04300000)
            . 'mntrRGB XYZ '
            . pack('n6', 2026, 1, 1, 0, 0, 0)
            . 'acsp'
            . str_repeat("\0", 88)
            . pack('N', 0);
    }

    private function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\x0D\x0A\x1A\x0A"
            . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82";
    }
}
