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

use Alto\Image\Size;

/**
 * Reports a source or output that exceeds configured safety limits.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class LimitExceededException extends \RuntimeException implements ImageExceptionInterface
{
    public static function pixels(Size $size, int $maximum, string $origin): self
    {
        return new self(\sprintf(
            '%s is %s, which is %s pixels and over the %s pixel limit. '
            . 'Raise Limits::$maxPixels if this source is trusted; note that libgd allocates outside memory_limit, '
            . 'so this check is what stands between you and a decompression bomb.',
            $origin,
            $size,
            number_format($size->pixels()),
            number_format($maximum),
        ));
    }

    public static function dimension(Size $size, int $maximum, string $origin): self
    {
        return new self(\sprintf(
            '%s is %s, and %s exceeds the %s pixel per-axis limit. Raise Limits::$maxDimension if this is intended.',
            $origin,
            $size,
            number_format(max($size->width, $size->height)),
            number_format($maximum),
        ));
    }

    public static function frames(int $frames, int $maximum, string $origin): self
    {
        return new self(\sprintf(
            '%s holds %d frames, over the %d frame limit. Raise Limits::$maxFrames if this source is trusted.',
            $origin,
            $frames,
            $maximum,
        ));
    }

    public static function bytes(int $bytes, int $maximum, string $origin): self
    {
        return new self(\sprintf(
            '%s is %s bytes, over the %s byte limit. Raise Limits::$maxBytes if this source is trusted.',
            $origin,
            number_format($bytes),
            number_format($maximum),
        ));
    }
}
