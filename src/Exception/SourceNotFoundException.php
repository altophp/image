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

namespace Alto\Image\Exception;

/**
 * Reports a missing or unreadable file source.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class SourceNotFoundException extends \RuntimeException implements ImageExceptionInterface
{
    public static function at(string $path): self
    {
        if (!file_exists($path)) {
            return new self(\sprintf('No such file: "%s".', $path));
        }

        if (is_dir($path)) {
            return new self(\sprintf('"%s" is a directory, not an image.', $path));
        }

        return new self(\sprintf('"%s" exists but is not readable by this process.', $path));
    }
}
