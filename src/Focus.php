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
 * A driver-resolved strategy for content-aware cropping.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum Focus: string
{
    /**
     * Keeps the region with the highest Shannon entropy.
     */
    case Entropy = 'entropy';

    /**
     * Keeps the region with the strongest local luminance changes.
     */
    case Attention = 'attention';
}
