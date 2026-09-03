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

use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\FocalPoint;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FocalPoint::class)]
final class FocalPointTest extends TestCase
{
    public function testItParsesFormatsAndPositionsANormalisedPoint(): void
    {
        $point = FocalPoint::parse('0.75x0.25');

        self::assertSame('0.75x0.25', (string) $point);
        self::assertSame([650, 50], $point->offsetIn(new Size(1000, 600), new Size(200, 200)));
        self::assertSame([0, 0], (new FocalPoint(0.0, 0.0))->offsetIn(new Size(100, 100), new Size(40, 40)));
        self::assertSame([60, 60], (new FocalPoint(1.0, 1.0))->offsetIn(new Size(100, 100), new Size(40, 40)));
    }

    public function testCoordinatesMustBeNormalised(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both axes must fall');

        new FocalPoint(1.1, 0.5);
    }

    public function testCoordinatesMustBeFinite(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FocalPoint(\NAN, 0.5);
    }

    public function testTheTextFormNeedsTwoNumericAxes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reads as');

        FocalPoint::parse('left-middle');
    }
}
