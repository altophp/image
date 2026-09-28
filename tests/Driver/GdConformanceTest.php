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
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Support;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\MetadataPolicy;
use Alto\Image\Source;
use Alto\Image\Tests\Support\DriverTestCase;

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

    public function testKeepingAnEmbeddedProfileIsRefused(): void
    {
        $source = self::corpus()->path('display-p3.jpg');
        $metadata = Image::open($source)->sourceMetadata();

        self::assertSame('embedded', $metadata->icc);
        self::assertSame(
            Support::No,
            $this->driver()->canEncode(new Encoding(Format::Jpeg, metadata: MetadataPolicy::ColourProfile), $metadata),
        );
    }

    public function testAnimatedInputIsReportedAsAOneFrameApproximation(): void
    {
        $result = Image::open(self::corpus()->path('animation.gif'))
            ->using($this->driver())
            ->fit(16, 16)
            ->encode(Format::Gif)
            ->bytes();

        self::assertSame(1, Source::bytes($result)->metadata()->frames);
        self::assertSame(Support::Approximate, $this->driver()->canDecode(Format::Gif));
    }
}
