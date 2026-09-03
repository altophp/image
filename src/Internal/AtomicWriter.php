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

use Alto\Image\Exception\StoreException;

/**
 * Writes complete files atomically within a local filesystem.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class AtomicWriter
{
    public static function write(string $path, string $bytes): int
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw StoreException::notWritable($directory, 'create the directory');
        }

        // The temp file has to share the destination's filesystem, or the rename
        // degrades to a copy and stops being atomic.
        $temp = @tempnam($directory, '.alto');

        if (false === $temp) {
            // @codeCoverageIgnoreStart
            throw StoreException::notWritable($directory, 'create a temporary file');
            // @codeCoverageIgnoreEnd
        }

        try {
            self::spill($temp, $bytes);

            // tempnam() creates at 0600, which would make the file unreadable by
            // a web server running as another user.
            @chmod($temp, 0o666 & ~umask());

            if (!@rename($temp, $path)) {
                throw StoreException::notWritable($path, 'rename the temporary file into place');
            }
        } catch (\Throwable $error) {
            @unlink($temp);

            throw $error;
        }

        return \strlen($bytes);
    }

    private static function spill(string $temp, string $bytes): void
    {
        $handle = @fopen($temp, 'wb');

        if (false === $handle) {
            // @codeCoverageIgnoreStart
            throw StoreException::notWritable($temp, 'open the temporary file');
            // @codeCoverageIgnoreEnd
        }

        try {
            $written = fwrite($handle, $bytes);

            if (false === $written || $written !== \strlen($bytes)) {
                // @codeCoverageIgnoreStart
                throw StoreException::notWritable($temp, 'write every byte to');
                // @codeCoverageIgnoreEnd
            }

            // Without this the rename can land before the data does, and a
            // crash leaves a correctly named file full of zeroes.
            fflush($handle);
            @fsync($handle);
        } finally {
            fclose($handle);
        }
    }
}
