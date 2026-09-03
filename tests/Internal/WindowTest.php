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

namespace Alto\Image\Tests\Internal;

use Alto\Image\Internal\Window;
use Alto\Image\Operation\Placement;
use Alto\Image\Size;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Window::class)]
final class WindowTest extends SourceClassTestCase
{
    protected const string SUBJECT = Window::class;

    public function testMapsScaledCropCoordinatesBackToTheSource(): void
    {
        $window = Window::of(new Placement(1000, 750, 500, 400, 100, 50), new Size(4000, 3000), 100, 50);

        self::assertSame(400, $window->sourceX);
        self::assertSame(200, $window->sourceY);
        self::assertSame(2000, $window->sourceWidth);
        self::assertSame(1600, $window->sourceHeight);
        self::assertSame(500, $window->width);
        self::assertSame(400, $window->height);
        self::assertFalse($window->isIdentity());
    }

    public function testRecognisesAnIdentityWindow(): void
    {
        $window = Window::of(Placement::scale(new Size(400, 300)), new Size(400, 300), 0, 0);

        self::assertTrue($window->isIdentity());
    }
}
