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

use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Support;
use Alto\Image\Format;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdDriver::class)]
final class RuntimeCoverageTest extends TestCase
{
    public function testGdReportsLosslessAvifAsAnApproximationWhenAvailable(): void
    {
        if (!GdDriver::isAvailable()) {
            self::markTestSkipped('ext-gd is not installed.');
        }

        $driver = new GdDriver();

        if (Support::No === $driver->canEncode(new Encoding(Format::Avif))) {
            self::markTestSkipped('This GD build cannot encode AVIF.');
        }

        self::assertSame(
            Support::Approximate,
            $driver->canEncode(new Encoding(Format::Avif, lossless: true)),
        );
    }
}
