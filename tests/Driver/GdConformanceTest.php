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
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\MetadataPolicy;
use Alto\Image\Test\DriverTestCase;

/**
 * The conformance kit, applied to the gd driver.
 *
 * There is nothing in this file but the driver, which is the point: a
 * third-party driver inherits the same assertions by writing the same six lines.
 */
final class GdConformanceTest extends DriverTestCase
{
    protected function driver(): DriverInterface
    {
        return new GdDriver();
    }

    /**
     * PHP's GD binding decodes Adam7 PNGs correctly, but current libgd builds
     * print a libpng warning that the binding cannot collect or suppress.
     * Other drivers still inherit the interlaced fixture from the kit.
     *
     * @return array<string, string>
     */
    protected function readableFixtures(): array
    {
        $fixtures = parent::readableFixtures();
        unset($fixtures['png interlaced']);

        return $fixtures;
    }

    public function testDroppingAnEmbeddedProfileIsReported(): void
    {
        $source = self::corpus()->path('display-p3.jpg');
        $result = Image::open($source)
            ->using($this->driver())
            ->fit(64, 64)
            ->encode(Format::Jpeg, metadata: MetadataPolicy::ColourProfile)
            ->render();

        self::assertSame('embedded', Image::open($source)->sourceMetadata()->icc);
        self::assertNull($result->metadata->icc);
        self::assertStringContainsString('dropped the embedded ICC profile', implode("\n", $result->degradations));
    }
}
