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

namespace Alto\Image;

/**
 * A fixed alignment point for placing an inner box inside an outer one.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum Anchor: string
{
    case TopLeft = 'top-left';
    case Top = 'top';
    case TopRight = 'top-right';
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
    case BottomLeft = 'bottom-left';
    case Bottom = 'bottom';
    case BottomRight = 'bottom-right';

    /**
     * Where the inner box sits inside the outer one.
     *
     * @return array{int, int} x and y, never negative
     */
    public function offsetIn(Size $outer, Size $inner): array
    {
        $freeX = max(0, $outer->width - $inner->width);
        $freeY = max(0, $outer->height - $inner->height);

        return [
            match ($this) {
                self::TopLeft, self::Left, self::BottomLeft => 0,
                self::Top, self::Center, self::Bottom => intdiv($freeX, 2),
                self::TopRight, self::Right, self::BottomRight => $freeX,
            },
            match ($this) {
                self::TopLeft, self::Top, self::TopRight => 0,
                self::Left, self::Center, self::Right => intdiv($freeY, 2),
                self::BottomLeft, self::Bottom, self::BottomRight => $freeY,
            },
        ];
    }
}
