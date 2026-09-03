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
 * Reports a derivative store read or write failure.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class StoreException extends \RuntimeException implements ImageExceptionInterface
{
    public static function notWritable(string $path, string $doing): self
    {
        return new self(\sprintf('Could not %s at "%s". Check the directory exists and is writable.', $doing, $path));
    }
}
