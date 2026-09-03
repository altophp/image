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

namespace Alto\Image\Analyzer;

use Alto\Image\Colour;
use Alto\Image\Exception\InvalidArgumentException;

/**
 * Extracts an image's dominant colours in descending frequency.
 *
 * @implements AnalyzerInterface<list<array{colour: string, packed: int, share: float}>>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DominantColors implements AnalyzerInterface
{
    /**
     * @param int $count  how many to return
     * @param int $levels the quantisation grid per channel; 4 gives 64 buckets
     */
    public function __construct(
        public int $count = 5,
        public int $levels = 4,
    ) {
        if ($count < 1) {
            throw new InvalidArgumentException(\sprintf('DominantColors returns at least one colour, got %d.', $count));
        }

        if ($levels < 2 || $levels > 16) {
            throw new InvalidArgumentException(\sprintf('The quantisation grid falls in [2, 16], got %d.', $levels));
        }
    }

    /**
     * @return list<array{colour: string, packed: int, share: float}>
     */
    public function analyze(Raster $raster): array
    {
        $buckets = [];
        $counted = 0;

        for ($y = 0; $y < $raster->height; ++$y) {
            for ($x = 0; $x < $raster->width; ++$x) {
                [$red, $green, $blue, $alpha] = $raster->rgba($x, $y);

                // A pixel you cannot see is not a colour the image is made of.
                if ($alpha < 128) {
                    continue;
                }

                $key = $this->bucket($red) . '-' . $this->bucket($green) . '-' . $this->bucket($blue);
                $bucket = $buckets[$key] ?? [0, 0, 0, 0];
                $buckets[$key] = [$bucket[0] + $red, $bucket[1] + $green, $bucket[2] + $blue, $bucket[3] + 1];
                ++$counted;
            }
        }

        if (0 === $counted) {
            return [];
        }

        uasort($buckets, static fn(array $a, array $b): int => $b[3] <=> $a[3]);

        $dominant = [];

        foreach (\array_slice($buckets, 0, $this->count) as [$red, $green, $blue, $members]) {
            $packed = (0xFF << 24)
                | (intdiv($red, $members) << 16)
                | (intdiv($green, $members) << 8)
                | intdiv($blue, $members);

            $dominant[] = [
                'colour' => '#' . Colour::format($packed),
                'packed' => $packed,
                'share' => round($members / $counted, 4),
            ];
        }

        return $dominant;
    }

    private function bucket(int $channel): int
    {
        return min($this->levels - 1, intdiv($channel * $this->levels, 256));
    }
}
