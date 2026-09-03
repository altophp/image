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
 * Reads the EXIF orientation tag without requiring ext-exif.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ExifReader
{
    private const int ORIENTATION_TAG = 0x0112;

    /**
     * The upright value, and the answer to every question this class cannot resolve.
     */
    private const int UPRIGHT = 1;

    public static function orientation(string $head): int
    {
        $app1 = self::findApp1($head);

        return null === $app1 ? self::UPRIGHT : self::readIfd0($app1);
    }

    /**
     * Walks the JPEG marker chain to the APP1 segment holding "Exif\0\0".
     */
    private static function findApp1(string $head): ?string
    {
        if (!str_starts_with($head, "\xFF\xD8")) {
            // A bare TIFF is its own header, so there is no segment to find.
            return str_starts_with($head, "II\x2A\x00") || str_starts_with($head, "MM\x00\x2A") ? $head : null;
        }

        $at = 2;
        $length = \strlen($head);

        while ($at + 4 <= $length) {
            if ("\xFF" !== $head[$at]) {
                return null;
            }

            $marker = \ord($head[$at + 1]);

            // Start of scan: the entropy-coded data begins and there are no more segments.
            if (0xDA === $marker || 0xD9 === $marker) {
                return null;
            }

            // Padding and the standalone markers carry no length field.
            if (0xFF === $marker || ($marker >= 0xD0 && $marker <= 0xD8) || 0x01 === $marker) {
                ++$at;

                continue;
            }

            /** @var array{n: int}|false $unpacked */
            $unpacked = unpack('nn', substr($head, $at + 2, 2));

            if (false === $unpacked || $unpacked['n'] < 2) {
                return null;
            }

            $segment = $unpacked['n'];

            if (0xE1 === $marker && "Exif\x00\x00" === substr($head, $at + 4, 6)) {
                // Bound the untrusted claimed length to the bytes read.
                return substr($head, $at + 10, min($segment - 8, $length - $at - 10));
            }

            $at += 2 + $segment;
        }

        return null;
    }

    private static function readIfd0(string $tiff): int
    {
        $endian = substr($tiff, 0, 2);

        if ('II' !== $endian && 'MM' !== $endian) {
            return self::UPRIGHT;
        }

        $long = 'II' === $endian ? 'V' : 'N';
        $short = 'II' === $endian ? 'v' : 'n';
        $length = \strlen($tiff);

        $offset = self::unpack($tiff, 4, $long, 4);

        // The directory has to start after the eight-byte header and leave room
        // for its own entry count, and nothing may point past what was read.
        if (null === $offset || $offset < 8 || $offset + 2 > $length) {
            return self::UPRIGHT;
        }

        $entries = self::unpack($tiff, $offset, $short, 2);

        if (null === $entries || 0 === $entries || $offset + 2 + $entries * 12 > $length) {
            return self::UPRIGHT;
        }

        for ($i = 0; $i < $entries; ++$i) {
            $entry = $offset + 2 + $i * 12;

            if (self::ORIENTATION_TAG !== self::unpack($tiff, $entry, $short, 2)) {
                continue;
            }

            // Type 3 is SHORT, and the value sits inline in the first two bytes
            // of the value field because one SHORT fits in four bytes.
            if (3 !== self::unpack($tiff, $entry + 2, $short, 2)) {
                return self::UPRIGHT;
            }

            $value = self::unpack($tiff, $entry + 8, $short, 2);

            return null !== $value && $value >= 1 && $value <= 8 ? $value : self::UPRIGHT;
        }

        return self::UPRIGHT;
    }

    private static function unpack(string $tiff, int $at, string $format, int $width): ?int
    {
        $slice = substr($tiff, $at, $width);

        if (\strlen($slice) < $width) {
            return null;
        }

        /** @var array{n: int}|false $unpacked */
        $unpacked = unpack($format . 'n', $slice);

        return false === $unpacked ? null : $unpacked['n'];
    }
}
