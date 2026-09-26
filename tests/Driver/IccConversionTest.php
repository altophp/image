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

use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Driver\Imagick\ImagickPipeline;
use Alto\Image\Driver\Support;
use Alto\Image\Exception\DriverException;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\Operation\IccConvert;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImagickDriver::class)]
#[CoversClass(ImagickPipeline::class)]
#[CoversClass(IccConvert::class)]
final class IccConversionTest extends TestCase
{
    private const string DISPLAY_P3 = 'AAAByGxjbXMCEAAAbW50clJHQiBYWVogB+IAAwAUAAkADgAdYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbWAAEAAAAA0y1oYW5ktKrdHxPIAzz1URRFKHqY4gAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAJZGVzYwAAAPAAAABeY3BydAAAAQwAAAAMd3RwdAAAARgAAAAUclhZWgAAASwAAAAUZ1hZWgAAAUAAAAAUYlhZWgAAAVQAAAAUclRSQwAAAWgAAABgZ1RSQwAAAWgAAABgYlRSQwAAAWgAAABgZGVzYwAAAAAAAAAEdVAzAAAAAAAAAAAAAAAAAHRleHQAAAAAQ0MwAFhZWiAAAAAAAADzUQABAAAAARbMWFlaIAAAAAAAAIPfAAA9v////7tYWVogAAAAAAAASr8AALE3AAAKuVhZWiAAAAAAAAAoOAAAEQoAAMi5Y3VydgAAAAAAAAAqAAAAfAD4AZwCdQODBMkGTggSChgMYg70Ec8U9hhqHC4gQySsKWoufjPrObM/1kZXTTZUdlwXZB1shnVWfo2ILJI2nKunjLLbvpnKx9dl5Hfx+f//';

    private string $directory = '';
    private ?ImagickDriver $driver = null;

    protected function setUp(): void
    {
        if (!ImagickDriver::isAvailable()) {
            self::markTestSkipped('ext-imagick is not installed.');
        }

        $this->driver = new ImagickDriver();

        if (Support::No === $this->driver->supports(new IccConvert())) {
            self::markTestSkipped('This ImageMagick build has no colour management support.');
        }

        $this->directory = sys_get_temp_dir() . '/alto-icc-' . bin2hex(random_bytes(6));

        if (!mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            self::fail('Could not create the ICC test directory.');
        }
    }

    protected function tearDown(): void
    {
        if ('' === $this->directory) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{string, string, string, Format, Format}>
     */
    public static function namedProfiles(): iterable
    {
        yield 'sRGB from CMYK' => ['srgb', 'srgb', 'cmyk', Format::Jpeg, Format::Png];
        yield 'gray' => ['gray', 'gray', 'srgb', Format::Png, Format::Png];
        yield 'grey alias' => ['grey', 'gray', 'srgb', Format::Png, Format::Png];
        yield 'CMYK from sRGB' => ['cmyk', 'cmyk', 'srgb', Format::Png, Format::Jpeg];
    }

    #[DataProvider('namedProfiles')]
    public function testNamedProfilesChangeTheEncodedColourSpace(
        string $profile,
        string $expected,
        string $sourceColourSpace,
        Format $sourceFormat,
        Format $outputFormat,
    ): void {
        $source = $this->imageFixture('source.' . $sourceFormat->value, $sourceColourSpace, $sourceFormat->value);

        $bytes = Image::open($source)
            ->using($this->driver())
            ->convertColourProfile($profile)
            ->encode($outputFormat)
            ->bytes();
        $rendered = new \Imagick();
        $rendered->readImageBlob($bytes);
        $expectedColourSpace = match ($expected) {
            'srgb' => \Imagick::COLORSPACE_SRGB,
            'gray' => \Imagick::COLORSPACE_GRAY,
            'cmyk' => \Imagick::COLORSPACE_CMYK,
            default => throw new \LogicException('Unknown expected colour space.'),
        };

        self::assertSame($expectedColourSpace, $rendered->getImageColorspace());
        self::assertSame($expected, Source::bytes($bytes)->metadata()->colourSpace);

        if ('gray' === $expected) {
            $pixel = $rendered->getImagePixelColor(0, 0)->getColor();
            self::assertSame($pixel['r'], $pixel['g']);
            self::assertSame($pixel['g'], $pixel['b']);
        }

        $rendered->clear();
    }

    public function testARealProfileFileIsEmbeddedInTheRenderedImage(): void
    {
        $source = $this->imageFixture('source.jpg', 'srgb', 'jpeg');
        $profileBytes = self::displayP3();
        $profile = $this->fixture('display-p3.icc', $profileBytes);

        $bytes = Image::open($source)
            ->using($this->driver())
            ->convertColourProfile($profile)
            ->jpeg(100)
            ->bytes();
        $rendered = new \Imagick();
        $rendered->readImageBlob($bytes);
        $profiles = $rendered->getImageProfiles('icc', true);
        $embedded = $profiles['icc'] ?? null;

        self::assertIsString($embedded);
        self::assertSame(hash('sha256', $profileBytes), hash('sha256', $embedded));
        self::assertSame('embedded', Source::bytes($bytes)->metadata()->icc);

        $rendered->clear();
    }

    public function testMissingAndRejectedProfilesHaveDistinctDriverErrors(): void
    {
        $source = $this->imageFixture('source.jpg', 'srgb', 'jpeg');
        $missing = $this->directory . '/missing.icc';
        $invalid = $this->fixture('invalid.icc', 'not an ICC profile');

        foreach ([$missing => 'reading', $invalid => 'applying'] as $path => $doing) {
            try {
                Image::open($source)
                    ->using($this->driver())
                    ->convertColourProfile($path)
                    ->jpeg(100)
                    ->bytes();
                self::fail('An unusable ICC profile was applied.');
            } catch (DriverException $error) {
                self::assertStringContainsString('imagick failed while ' . $doing . ' the ICC profile', $error->getMessage());
            }
        }
    }

    private function driver(): ImagickDriver
    {
        if (null === $this->driver) {
            self::fail('The Imagick driver was not initialised.');
        }

        return $this->driver;
    }

    private function imageFixture(string $name, string $colourSpace, string $format): string
    {
        $path = $this->directory . '/' . $name;
        $image = new \Imagick();
        $image->newImage(4, 4, new \ImagickPixel('rgb(196, 76, 24)'));
        $image->setImageColorspace(match ($colourSpace) {
            'srgb' => \Imagick::COLORSPACE_SRGB,
            'cmyk' => \Imagick::COLORSPACE_CMYK,
            default => throw new \LogicException('Unknown source colour space.'),
        });
        $image->setImageFormat($format);

        if ('jpeg' === $format) {
            $image->setImageCompressionQuality(100);
        }

        $image->writeImage($path);
        $image->clear();

        return $path;
    }

    private function fixture(string $name, string $bytes): string
    {
        $path = $this->directory . '/' . $name;

        if (false === file_put_contents($path, $bytes)) {
            self::fail('Could not write the ICC fixture.');
        }

        return $path;
    }

    private static function displayP3(): string
    {
        $profile = base64_decode(self::DISPLAY_P3, true);

        if (false === $profile) {
            self::fail('The embedded Display P3 profile is invalid.');
        }

        return $profile;
    }
}
