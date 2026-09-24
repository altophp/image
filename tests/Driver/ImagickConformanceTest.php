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
use Alto\Image\Test\DriverTestCase;
use Alto\Image\Test\IccProfile;

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

    public function testColourProfilePreservesARealIccProfileAndStripRemovesIt(): void
    {
        $source = self::corpus()->path('display-p3.jpg');
        $kept = Image::open($source)
            ->using($this->driver())
            ->fit(64, 64)
            ->encode(Format::Jpeg, metadata: MetadataPolicy::ColourProfile)
            ->render();
        $stripped = Image::open($source)
            ->using($this->driver())
            ->fit(64, 64)
            ->encode(Format::Jpeg, metadata: MetadataPolicy::Strip)
            ->render();
        $keptImage = new \Imagick();
        $keptImage->readImageBlob($kept->bytes);
        $strippedImage = new \Imagick();
        $strippedImage->readImageBlob($stripped->bytes);
        $profiles = $keptImage->getImageProfiles('icc', true);
        $profile = $profiles['icc'] ?? null;

        self::assertSame('embedded', $kept->metadata->icc);
        self::assertIsString($profile);
        self::assertSame(hash('sha256', IccProfile::displayP3()), hash('sha256', $profile));
        self::assertSame([], $strippedImage->getImageProfiles('icc', true));
        self::assertNull($stripped->metadata->icc);
    }
}
