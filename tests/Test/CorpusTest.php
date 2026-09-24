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

namespace Alto\Image\Tests\Test;

use Alto\Image\Format;
use Alto\Image\Source;
use Alto\Image\Test\Corpus;
use Alto\Image\Test\DeviceExif;
use Alto\Image\Test\IccProfile;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class CorpusTest extends SourceClassTestCase
{
    protected const string SUBJECT = Corpus::class;

    public function testTheInterlacedFixtureHasReadableMetadata(): void
    {
        $metadata = Source::file(Corpus::shared()->path('interlaced.png'))->metadata();

        self::assertSame(Format::Png, $metadata->format);
        self::assertSame('192x192', (string) $metadata->size);
    }

    public function testItBuildsTheHardJpegVariantsWhenImagickIsAvailable(): void
    {
        if (!\extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            self::markTestSkipped('The conditional JPEG fixtures need ext-imagick.');
        }

        $corpus = Corpus::shared();
        $progressive = (string) file_get_contents($corpus->path('progressive.jpg'));

        self::assertStringContainsString("\xFF\xC2", $progressive, 'The progressive fixture has no SOF2 marker.');
        self::assertSame('gray', Source::file($corpus->path('grayscale.jpg'))->metadata()->colourSpace);
        self::assertSame('cmyk', Source::file($corpus->path('cmyk.jpg'))->metadata()->colourSpace);
    }

    public function testItBuildsAJpegWithRealDeviceExif(): void
    {
        $path = Corpus::shared()->path('device-exif.jpg');
        $bytes = (string) file_get_contents($path);
        $segment = substr($bytes, 2, \strlen(DeviceExif::segment()));
        $metadata = Source::file($path)->metadata();

        self::assertTrue($metadata->hasMetadata);
        self::assertSame(1, $metadata->orientation);
        self::assertSame(hash('sha256', DeviceExif::segment()), hash('sha256', $segment));
        self::assertStringContainsString("Canon EOS 5D Mark II\x00", $segment);
        self::assertStringContainsString("EF70-300mm f/4-5.6 IS USM\x00", $segment);
    }

    public function testItBuildsARealProfiledJpegWhenImagickIsAvailable(): void
    {
        if (!\extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            self::markTestSkipped('The conditional ICC fixture needs ext-imagick.');
        }

        $path = Corpus::shared()->path('display-p3.jpg');
        $metadata = Source::file($path)->metadata();
        $image = new \Imagick($path);
        $profiles = $image->getImageProfiles('icc', true);
        $profile = $profiles['icc'] ?? null;

        self::assertSame('embedded', $metadata->icc);
        self::assertIsString($profile);
        self::assertSame(hash('sha256', IccProfile::displayP3()), hash('sha256', $profile));
    }
}
