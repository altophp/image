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
 * The encoder effort used to trade speed for smaller output.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum Effort: string
{
    /**
     * As fast as the encoder goes. For previews and hot paths.
     */
    case Fast = 'fast';

    /**
     * The encoder's own default.
     */
    case Balanced = 'balanced';

    /**
     * As small as the encoder goes. For build-time and cached derivatives.
     */
    case Best = 'best';

    /**
     * The AVIF and WebP speed knob, where 0 is slowest and 10 is fastest.
     */
    public function speed(): int
    {
        return match ($this) {
            self::Fast => 8,
            self::Balanced => 6,
            self::Best => 2,
        };
    }

    /**
     * The PNG zlib compression level.
     */
    public function compression(): int
    {
        return match ($this) {
            self::Fast => 1,
            self::Balanced => 6,
            self::Best => 9,
        };
    }
}
