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

use Alto\Image\Exception\InvalidArgumentException;

/**
 * Parses and formats colours represented as packed 0xAARRGGBB integers.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Colour
{
    public const int TRANSPARENT = 0x00000000;

    private const array NAMES = [
        'transparent' => 0x00000000,
        'black' => 0xFF000000,
        'silver' => 0xFFC0C0C0,
        'gray' => 0xFF808080,
        'grey' => 0xFF808080,
        'white' => 0xFFFFFFFF,
        'maroon' => 0xFF800000,
        'red' => 0xFFFF0000,
        'purple' => 0xFF800080,
        'fuchsia' => 0xFFFF00FF,
        'green' => 0xFF008000,
        'lime' => 0xFF00FF00,
        'olive' => 0xFF808000,
        'yellow' => 0xFFFFFF00,
        'navy' => 0xFF000080,
        'blue' => 0xFF0000FF,
        'teal' => 0xFF008080,
        'aqua' => 0xFF00FFFF,
    ];

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $value): int
    {
        $text = strtolower(trim($value));

        if (isset(self::NAMES[$text])) {
            return self::NAMES[$text];
        }

        $hex = ltrim($text, '#');

        if (1 === preg_match('/^[0-9a-f]{3,8}$/', $hex)) {
            $packed = self::fromHex($hex);

            if (null !== $packed) {
                return $packed;
            }
        }

        $packed = self::fromFunction($text);

        if (null !== $packed) {
            return $packed;
        }

        throw new InvalidArgumentException(\sprintf(
            'Could not read "%s" as a colour. Try #rgb, #rrggbb, #rrggbbaa, rgb(r, g, b), rgba(r, g, b, a), or one of: %s.',
            $value,
            implode(', ', array_keys(self::NAMES)),
        ));
    }

    /**
     * The shortest hex form that round-trips, without a leading hash.
     */
    public static function format(int $packed): string
    {
        $alpha = ($packed >> 24) & 0xFF;
        $rgb = sprintf('%06x', $packed & 0xFFFFFF);

        return 0xFF === $alpha ? $rgb : $rgb . sprintf('%02x', $alpha);
    }

    /**
     * @return int<0, 255>
     */
    public static function alpha(int $packed): int
    {
        /** @var int<0, 255> */
        return ($packed >> 24) & 0xFF;
    }

    /**
     * @return int<0, 255>
     */
    public static function red(int $packed): int
    {
        /** @var int<0, 255> */
        return ($packed >> 16) & 0xFF;
    }

    /**
     * @return int<0, 255>
     */
    public static function green(int $packed): int
    {
        /** @var int<0, 255> */
        return ($packed >> 8) & 0xFF;
    }

    /**
     * @return int<0, 255>
     */
    public static function blue(int $packed): int
    {
        /** @var int<0, 255> */
        return $packed & 0xFF;
    }

    public static function isOpaque(int $packed): bool
    {
        return 0xFF === self::alpha($packed);
    }

    private static function fromHex(string $hex): ?int
    {
        $expanded = match (\strlen($hex)) {
            3 => $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] . 'ff',
            4 => $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3],
            6 => $hex . 'ff',
            8 => $hex,
            default => null,
        };

        if (null === $expanded) {
            return null;
        }

        return (int) hexdec(substr($expanded, 6, 2) . substr($expanded, 0, 6));
    }

    private static function fromFunction(string $text): ?int
    {
        if (1 !== preg_match('/^rgba?\(([^)]*)\)$/', $text, $matches)) {
            return null;
        }

        $parts = preg_split('/[\s,\/]+/', trim($matches[1]), -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $parts || \count($parts) < 3 || \count($parts) > 4) {
            return null;
        }

        $channels = [];

        foreach (\array_slice($parts, 0, 3) as $part) {
            if (!is_numeric(rtrim($part, '%'))) {
                return null;
            }

            $number = (float) rtrim($part, '%');
            $channels[] = max(0, min(255, (int) round(str_ends_with($part, '%') ? $number * 2.55 : $number)));
        }

        $alpha = 255;

        if (4 === \count($parts)) {
            if (!is_numeric(rtrim($parts[3], '%'))) {
                return null;
            }

            $number = (float) rtrim($parts[3], '%');
            $alpha = max(0, min(255, (int) round(str_ends_with($parts[3], '%') ? $number * 2.55 : $number * 255)));
        }

        return ($alpha << 24) | ($channels[0] << 16) | ($channels[1] << 8) | $channels[2];
    }
}
