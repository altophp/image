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
 * Provides stat-based and content-based source fingerprint strategies.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Fingerprint
{
    /**
     * @return \Closure(string): string
     */
    public static function stat(): \Closure
    {
        return static function (string $path): string {
            $stat = @stat($path);

            if (false === $stat) {
                return hash('xxh128', $path);
            }

            return hash('xxh128', implode("\0", [
                $path,
                (string) $stat['ino'],
                (string) $stat['size'],
                (string) $stat['mtime'],
                (string) $stat['ctime'],
            ]));
        };
    }

    /**
     * @return \Closure(string): string
     */
    public static function content(): \Closure
    {
        return static function (string $path): string {
            $hash = @hash_file('xxh128', $path);

            return false === $hash ? hash('xxh128', $path) : $hash;
        };
    }

    /**
     * The fingerprint of bytes already in memory, which has no stat to consult.
     */
    public static function ofBytes(string $bytes): string
    {
        return hash('xxh128', $bytes);
    }
}
