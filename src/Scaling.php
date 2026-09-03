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
 * Limits the direction in which a resize may scale an image.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum Scaling: string
{
    /**
     * Shrink or enlarge, whichever the box asks for.
     */
    case Both = 'both';

    /**
     * Shrink only. A source smaller than the box is left alone.
     */
    case Down = 'down';

    /**
     * Enlarge only. A source larger than the box is left alone.
     */
    case Up = 'up';

    /**
     * Never scale. Geometry still resolves, so a crop still happens.
     */
    case None = 'none';

    /**
     * Clamps a required scale factor to what this policy permits.
     */
    public function clamp(float $scale): float
    {
        return match ($this) {
            self::Both => $scale,
            self::Down => min($scale, 1.0),
            self::Up => max($scale, 1.0),
            self::None => 1.0,
        };
    }
}
