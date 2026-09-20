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

namespace Alto\Image\Test;

/**
 * Builds uncommon PNG fixtures without relying on an image extension.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class PngWriter
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Adam7, as seven passes of (first row, first column, row step, column step).
     */
    private const array PASSES = [
        [0, 0, 8, 8],
        [0, 4, 8, 8],
        [4, 0, 8, 4],
        [0, 2, 4, 4],
        [2, 0, 4, 2],
        [0, 1, 2, 2],
        [1, 0, 2, 1],
    ];

    /**
     * Truecolour at sixteen bits a channel.
     *
     * GD reads this and gives back eight bits, which is a real answer and not an
     * exact one. ImageMagick at Q16 keeps all of it.
     */
    public static function truecolour16(int $width, int $height): string
    {
        $rows = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = '';

            for ($x = 0; $x < $width; ++$x) {
                [$red, $green, $blue] = self::sample($x, $y);
                // Scale each eight-bit sample across the full sixteen-bit range
                // rather than into its top half, so a truncating reader loses
                // the low byte and nothing else.
                $row .= pack('n3', $red * 257, $green * 257, $blue * 257);
            }

            $rows[] = $row;
        }

        return self::assemble($width, $height, 16, 2, 0, $rows);
    }

    /**
     * Greyscale at sixteen bits, which is what a depth map or a scan looks like.
     */
    public static function grey16(int $width, int $height): string
    {
        $rows = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = '';

            for ($x = 0; $x < $width; ++$x) {
                [$red, $green, $blue] = self::sample($x, $y);
                $row .= pack('n', (int) round(0.299 * $red + 0.587 * $green + 0.114 * $blue) * 257);
            }

            $rows[] = $row;
        }

        return self::assemble($width, $height, 16, 0, 0, $rows);
    }

    /**
     * A palette, optionally with transparency in a `tRNS` chunk.
     *
     * This is the one that catches a driver deciding whether an image has alpha
     * by looking for an alpha channel. There is no channel here. Entry zero is
     * fully transparent and the rest are opaque, and an encoder that ignores
     * that writes a black or white block where the holes were.
     */
    public static function palette(int $width, int $height, bool $transparent): string
    {
        $entries = 64;
        $palette = '';

        for ($index = 0; $index < $entries; ++$index) {
            $palette .= pack('C3', $index * 4, 255 - $index * 4, ($index * 9) % 256);
        }

        $rows = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = '';

            for ($x = 0; $x < $width; ++$x) {
                [$red] = self::sample($x, $y);
                // A band of entry zero down the left edge, so the transparent
                // entry occupies a region an assertion can point at. The rest
                // index the palette, and an index past its last entry is a
                // malformed PNG rather than a dark pixel, so it is clamped to
                // one the chunk above actually wrote.
                $row .= \chr($x < intdiv($width, 8) ? 0 : max(1, min($entries - 1, intdiv($red, 4))));
            }

            $rows[] = $row;
        }

        $extra = self::chunk('PLTE', $palette);

        if ($transparent) {
            // One byte an entry, in palette order, and entries past the end of
            // the chunk are opaque. Only entry zero needs to be named.
            $extra .= self::chunk('tRNS', "\x00");
        }

        return self::assemble($width, $height, 8, 3, 0, $rows, $extra);
    }

    /**
     * Truecolour, interlaced, so that the first pass is not the top of the image.
     */
    public static function interlaced(int $width, int $height): string
    {
        $data = '';

        foreach (self::PASSES as [$firstRow, $firstColumn, $rowStep, $columnStep]) {
            $passWidth = $firstColumn >= $width ? 0 : intdiv($width - $firstColumn + $columnStep - 1, $columnStep);
            $passHeight = $firstRow >= $height ? 0 : intdiv($height - $firstRow + $rowStep - 1, $rowStep);

            if (0 !== $passWidth && 0 !== $passHeight) {
                for ($row = 0; $row < $passHeight; ++$row) {
                    $line = "\x00";

                    for ($column = 0; $column < $passWidth; ++$column) {
                        [$red, $green, $blue] = self::sample($firstColumn + $column * $columnStep, $firstRow + $row * $rowStep);
                        $line .= pack('C3', $red, $green, $blue);
                    }

                    $data .= $line;
                }
            }
        }

        return self::SIGNATURE
            . self::chunk('IHDR', pack('N2C5', $width, $height, 8, 2, 0, 0, 1))
            . self::chunk('IDAT', (string) gzcompress($data, 6))
            . self::chunk('IEND', '');
    }

    /**
     * Deterministic content with detail at every frequency, so that a fixture
     * downscaled badly looks different from one downscaled well.
     *
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private static function sample(int $x, int $y): array
    {
        $radius = ($x - 96) ** 2 + ($y - 96) ** 2;

        return [
            self::byte(1 + sin($radius / 320)),
            self::byte(1 + sin($x / 7)),
            self::byte(1 + cos($y / 11)),
        ];
    }

    /**
     * A channel from a value between zero and two.
     *
     * @return int<0, 255>
     */
    private static function byte(float $value): int
    {
        return min(255, max(0, (int) (127.5 * $value)));
    }

    /**
     * @param list<string> $rows one scanline each, without its filter byte
     */
    private static function assemble(
        int $width,
        int $height,
        int $depth,
        int $colourType,
        int $interlace,
        array $rows,
        string $extra = '',
    ): string {
        $data = '';

        foreach ($rows as $row) {
            $data .= "\x00" . $row;
        }

        return self::SIGNATURE
            . self::chunk('IHDR', pack('N2C5', $width, $height, $depth, $colourType, 0, 0, $interlace))
            . $extra
            . self::chunk('IDAT', (string) gzcompress($data, 6))
            . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
