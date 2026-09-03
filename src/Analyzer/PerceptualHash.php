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

use Alto\Image\Exception\InvalidArgumentException;

/**
 * Computes a 64-bit DCT perceptual hash for visual comparison.
 *
 * @implements AnalyzerInterface<string>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class PerceptualHash implements AnalyzerInterface
{
    /**
     * The working square. 32 is the usual choice, and it fits under Raster's cap.
     */
    private const int SIZE = 32;

    /**
     * The low-frequency corner kept from the transform, which is 64 bits.
     */
    private const int KEPT = 8;

    public function analyze(Raster $raster): string
    {
        $square = $raster->resampledTo(self::SIZE);
        $rows = [];

        for ($y = 0; $y < self::SIZE; ++$y) {
            $row = [];

            for ($x = 0; $x < self::SIZE; ++$x) {
                $row[] = $square->luma($x, $y);
            }

            $rows[] = self::dct($row);
        }

        $frequencies = [];

        for ($x = 0; $x < self::KEPT; ++$x) {
            $column = self::dct(array_column($rows, $x));

            for ($y = 0; $y < self::KEPT; ++$y) {
                $frequencies[$y * self::KEPT + $x] = $column[$y];
            }
        }

        ksort($frequencies);

        // The DC term is the average brightness of the whole image, so leaving it
        // in the median would make every hash a hash of the exposure.
        $withoutDc = $frequencies;
        unset($withoutDc[0]);
        $median = self::median(array_values($withoutDc));

        $bits = '';

        foreach ($frequencies as $frequency) {
            $bits .= $frequency > $median ? '1' : '0';
        }

        return self::pack($bits);
    }

    /**
     * How many bits two hashes differ by. Zero is identical.
     *
     * @throws InvalidArgumentException when the two are not the same shape
     */
    public static function distance(string $left, string $right): int
    {
        if (\strlen($left) !== \strlen($right)) {
            throw new InvalidArgumentException(\sprintf(
                'Two hashes of different lengths cannot be compared: %d and %d hex digits.',
                \strlen($left),
                \strlen($right),
            ));
        }

        if (16 !== \strlen($left) || 1 !== preg_match('/^[0-9a-f]{16}$/i', $left) || 1 !== preg_match('/^[0-9a-f]{16}$/i', $right)) {
            throw new InvalidArgumentException('A perceptual hash is exactly 16 hexadecimal digits.');
        }

        $distance = 0;

        for ($i = 0, $length = \strlen($left); $i < $length; ++$i) {
            $distance += substr_count(decbin(hexdec($left[$i]) ^ hexdec($right[$i])), '1');
        }

        return $distance;
    }

    /**
     * A one-dimensional DCT-II. Naive, because 32 points cost nothing.
     *
     * @param list<float> $values
     *
     * @return list<float>
     */
    private static function dct(array $values): array
    {
        $length = \count($values);
        $out = [];

        for ($k = 0; $k < $length; ++$k) {
            $sum = 0.0;

            foreach ($values as $n => $value) {
                $sum += $value * cos(\M_PI * ($n + 0.5) * $k / $length);
            }

            $out[] = $sum * (0 === $k ? sqrt(1 / $length) : sqrt(2 / $length));
        }

        return $out;
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);

        // A 64-bit hash has 63 AC coefficients after its DC term is removed.
        return $values[intdiv(\count($values), 2)];
    }

    private static function pack(string $bits): string
    {
        $hex = '';

        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex((int) bindec($nibble));
        }

        return $hex;
    }
}
