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

namespace Alto\Image\Internal;

/**
 * Searches for the highest encoding quality under a byte ceiling.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class QualitySearch
{
    /**
     * The lowest acceptable quality when searching for a byte ceiling.
     */
    public const int FLOOR = 20;

    /**
     * @param \Closure(int): string $encode
     *
     * @return array{string, int, bool} the bytes, the quality used, and whether the ceiling was met
     */
    public static function under(int $maxBytes, int $ceiling, \Closure $encode): array
    {
        $best = $encode($ceiling);

        if (\strlen($best) <= $maxBytes) {
            return [$best, $ceiling, true];
        }

        $low = self::FLOOR;
        $high = $ceiling;
        $bestQuality = self::FLOOR;
        $found = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $candidate = $encode($middle);

            if (\strlen($candidate) <= $maxBytes) {
                $found = $candidate;
                $bestQuality = $middle;
                $low = $middle + 1;

                continue;
            }

            $high = $middle - 1;
        }

        // Nothing down to the floor fit, so the floor is what gets returned,
        // over the ceiling, along with the admission that it did not fit.
        return null === $found
            ? [$encode(self::FLOOR), self::FLOOR, false]
            : [$found, $bestQuality, true];
    }
}
