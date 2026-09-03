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

namespace Alto\Image\Driver\Gd;

/**
 * Resamples GD images with the package's fixed quality-preserving strategy.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Resampler
{
    public static function scale(\GdImage $image, int $width, int $height): \GdImage
    {
        $from = [imagesx($image), imagesy($image)];
        $width = max(1, $width);
        $height = max(1, $height);

        if ($from[0] === $width && $from[1] === $height) {
            return $image;
        }

        $scaled = imagecreatetruecolor($width, $height);

        // Blending off and alpha kept before the copy, or the resample
        // composites onto opaque black and the transparency is gone before
        // anything else in the pipeline gets a chance to see it.
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);

        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $width - 1, $height - 1, false === $transparent ? 0 : $transparent);

        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, $from[0], $from[1]);

        return $scaled;
    }

    /**
     * What this driver resamples with, for doctor and for a degradation line.
     */
    public static function name(): string
    {
        return 'area average (imagecopyresampled)';
    }
}
