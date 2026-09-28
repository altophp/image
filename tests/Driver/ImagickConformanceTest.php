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
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\MetadataPolicy;
use Alto\Image\Source;
use Alto\Image\Tests\Support\DriverTestCase;
use Alto\Image\Tests\Support\IccProfile;

/**
 * The conformance kit, applied to the imagick driver.
 *
 * There is nothing in this file but the driver, which is the point: a
 * third-party driver inherits the same assertions by writing the same six lines.
 */
final class ImagickConformanceTest extends DriverTestCase
{
    protected function driver(): DriverInterface
    {
        return new ImagickDriver();
    }

    public function testTheDefaultPreservesARealIccProfileAndStripRemovesIt(): void
    {
        $source = self::corpus()->path('display-p3.jpg');
        $kept = Image::open($source)
            ->using($this->driver())
            ->fit(64, 64)
            ->jpeg()
            ->bytes();
        $stripped = Image::open($source)
            ->using($this->driver())
            ->fit(64, 64)
            ->encode(Format::Jpeg, metadata: MetadataPolicy::Strip)
            ->bytes();
        $keptImage = new \Imagick();
        $keptImage->readImageBlob($kept);
        $strippedImage = new \Imagick();
        $strippedImage->readImageBlob($stripped);
        $profiles = $keptImage->getImageProfiles('icc', true);
        $profile = $profiles['icc'] ?? null;

        self::assertSame('embedded', Source::bytes($kept)->metadata()->icc);
        self::assertIsString($profile);
        self::assertSame(hash('sha256', IccProfile::displayP3()), hash('sha256', $profile));
        self::assertSame([], $strippedImage->getImageProfiles('icc', true));
        self::assertNull(Source::bytes($stripped)->metadata()->icc);
    }

    public function testTheDefaultStripsRealDeviceExifAndKeepRetainsIt(): void
    {
        $source = self::corpus()->path('device-exif.jpg');
        $stripped = Image::open($source)->using($this->driver())->fit(64, 64)->jpeg()->bytes();
        $kept = Image::open($source)
            ->using($this->driver())
            ->fit(64, 64)
            ->encode(Format::Jpeg, metadata: MetadataPolicy::Keep)
            ->bytes();

        self::assertFalse(Source::bytes($stripped)->metadata()->hasMetadata);
        self::assertStringNotContainsString("Exif\x00\x00", $stripped);
        self::assertTrue(Source::bytes($kept)->metadata()->hasMetadata);
        self::assertStringContainsString("Exif\x00\x00", $kept);
        self::assertStringContainsString("Canon EOS 5D Mark II\x00", $kept);
    }

    public function testItTransformsEveryFrameAndPreservesAnimationTiming(): void
    {
        $result = Image::open(self::corpus()->path('animation.gif'))
            ->using($this->driver())
            ->fit(16, 16)
            ->encode(Format::Gif)
            ->bytes();

        $image = new \Imagick();
        $image->readImageBlob($result);
        $delays = [];
        $sizes = [];

        foreach ($image as $frame) {
            $delays[] = $frame->getImageDelay();
            $sizes[] = [$frame->getImageWidth(), $frame->getImageHeight()];
        }

        self::assertSame(2, Source::bytes($result)->metadata()->frames);
        self::assertSame(2, $image->getNumberImages());
        self::assertSame(1, $image->getImageIterations());
        self::assertSame([50, 50], $delays);
        self::assertSame([[16, 16], [16, 16]], $sizes);
    }

    public function testConvertingAnAnimationToAStaticFormatProducesOneFrame(): void
    {
        $request = Image::open(self::corpus()->path('animation.gif'))
            ->using($this->driver())
            ->fit(16, 16)
            ->png();
        $result = $request->bytes();

        $image = new \Imagick();
        $image->readImageBlob($result);

        self::assertSame(2, $request->sourceMetadata()->frames);
        self::assertSame(1, $request->metadata()->frames);
        self::assertSame(1, Source::bytes($result)->metadata()->frames);
        self::assertSame(1, $image->getNumberImages());
    }
}
