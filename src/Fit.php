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
 * Defines how a source image is fitted to a requested box.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum Fit: string
{
    /**
     * Fills the box exactly, cropping the overflow.
     */
    case Cover = 'cover';

    /**
     * Fills the box exactly, padding the shortfall with a background.
     */
    case Contain = 'contain';

    /**
     * Fills the box exactly, distorting each axis independently.
     */
    case Fill = 'fill';

    /**
     * The largest size that fits inside the box, keeping the aspect ratio.
     */
    case Inside = 'inside';

    /**
     * The smallest size that covers the box, keeping the aspect ratio.
     */
    case Outside = 'outside';

    /**
     * Whether the produced size is exactly the requested box.
     *
     * Inside and Outside answer with a proportional size, so the box is a bound
     * rather than a promise.
     */
    public function isExact(): bool
    {
        return self::Inside !== $this && self::Outside !== $this;
    }
}
