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
}
