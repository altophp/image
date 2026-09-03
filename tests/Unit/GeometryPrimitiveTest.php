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

use Alto\Image\Anchor;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Size::class)]
#[CoversClass(Anchor::class)]
final class GeometryPrimitiveTest extends TestCase
{
    public function testASizeIsTwoIntegersAndItsOwnLabel(): void
    {
        $size = new Size(1280, 720);

        self::assertSame('1280x720', (string) $size);
        self::assertSame(921_600, $size->pixels());
        self::assertSame(round(16 / 9, 4), round($size->ratio(), 4));
        self::assertSame('720x1280', (string) $size->transposed());
        self::assertTrue($size->equals(new Size(1280, 720)));
        self::assertFalse($size->equals(new Size(1280, 721)));
    }

    public function testASizeCannotRepresentAnImpossibleImage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');

        new Size(0, 10);
    }

    /**
     * Scaling never produces a zero-pixel axis, because a zero-width image is a
     * crash in every driver rather than an unusual result.
     */
    #[DataProvider('extremeScales')]
    public function testScalingNeverReachesZero(int $width, int $height, float $factor): void
    {
        $scaled = (new Size($width, $height))->scaledBy($factor);

        self::assertGreaterThanOrEqual(1, $scaled->width);
        self::assertGreaterThanOrEqual(1, $scaled->height);
    }

    /**
     * @return iterable<string, array{int, int, float}>
     */
    public static function extremeScales(): iterable
    {
        yield 'a hundredth of a wide strip' => [10000, 1, 0.01];
        yield 'a thousandth of a square' => [1000, 1000, 0.001];
        yield 'a millionth of anything' => [4000, 3000, 0.000001];
        yield 'one pixel, halved' => [1, 1, 0.5];
    }

    /**
     * @return iterable<string, array{Anchor, int, int}>
     */
    public static function anchors(): iterable
    {
        // A 100x100 box inside a 300x200 one leaves 200 and 100 of slack.
        yield 'top-left' => [Anchor::TopLeft, 0, 0];
        yield 'top' => [Anchor::Top, 100, 0];
        yield 'top-right' => [Anchor::TopRight, 200, 0];
        yield 'left' => [Anchor::Left, 0, 50];
        yield 'center' => [Anchor::Center, 100, 50];
        yield 'right' => [Anchor::Right, 200, 50];
        yield 'bottom-left' => [Anchor::BottomLeft, 0, 100];
        yield 'bottom' => [Anchor::Bottom, 100, 100];
        yield 'bottom-right' => [Anchor::BottomRight, 200, 100];
    }

    #[DataProvider('anchors')]
    public function testTheNinePointGridPlacesTheInnerBox(Anchor $anchor, int $x, int $y): void
    {
        self::assertSame([$x, $y], $anchor->offsetIn(new Size(300, 200), new Size(100, 100)));
    }

    public function testAnAnchorNeverReturnsANegativeOffset(): void
    {
        foreach (Anchor::cases() as $anchor) {
            // An inner box larger than the outer one has no slack to distribute.
            [$x, $y] = $anchor->offsetIn(new Size(100, 100), new Size(300, 200));

            self::assertSame(0, $x);
            self::assertSame(0, $y);
        }
    }
}
