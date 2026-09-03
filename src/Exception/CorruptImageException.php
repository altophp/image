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
 * Reports image bytes that are corrupt, truncated, or unreadable.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class CorruptImageException extends \RuntimeException implements ImageExceptionInterface
{
    public static function truncated(string $origin, string $format, string $policy): self
    {
        return new self(\sprintf(
            "%s does not end the way a %s file ends, so it is truncated.\n"
            . "  No decoder will tell you this: libgd suppresses libjpeg's warnings and ImageMagick reconstructs what it can,\n"
            . "  so a file cut off at two per cent still decodes, at full size, with the rest filled in.\n"
            . '  Try: Limits::$failOn = FailOn::None to accept whatever rows survived. It is currently %s.',
            $origin,
            $format,
            $policy,
        ));
    }

    public static function unreadableHeader(string $origin): self
    {
        return new self(\sprintf(
            'Could not read an image header from %s. The bytes are truncated, empty, or not an image at all.',
            $origin,
        ));
    }
}
