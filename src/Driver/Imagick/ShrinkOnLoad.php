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

namespace Alto\Image\Driver\Imagick;

use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\Operation\Placement;
use Alto\Image\Size;

/**
 * Chooses an Imagick JPEG decode hint that safely covers every output.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ShrinkOnLoad
{
    /**
     * The DCT rungs libjpeg can reconstruct a scan at, as eighths.
     *
     * Modern libjpeg-turbo goes to sixteen eighths, but anything above eight is
     * an enlargement and there is nothing to gain from asking a decoder to do one.
     */
    private const int RUNGS = 8;

    /**
     * The smallest decode that still covers every output and projects the same.
     *
     * ImageMagick rounds jpeg:size to the nearest eighth and may decode below the
     * requested size. Select the smallest rung whose floored dimensions still
     * cover every output.
     *
     * Covering the geometry is not enough on its own. The pipeline solves every
     * step against the raster it is handed, so an aspect-preserving resize
     * solved against a 141x170 decode of a 563x678 source rounds to 100x121
     * where the projection said 100x120. A rung that moves a promised size is
     * not a cheaper decode of the same picture, so keep climbing.
     *
     * @param list<Placement>     $placements        the first geometry step of every output
     * @param \Closure(Size): bool $preservesGeometry whether a decode at that size still projects to every promised output
     *
     * @return string|null the jpeg:size hint, or null when there is nothing to gain
     */
    public static function hint(Metadata $source, array $placements, \Closure $preservesGeometry): ?string
    {
        if (Format::Jpeg !== $source->format || [] === $placements) {
            return null;
        }

        $width = 0;
        $height = 0;

        foreach ($placements as $placement) {
            $width = max($width, $placement->scaleWidth);
            $height = max($height, $placement->scaleHeight);
        }

        for ($rung = 1; $rung < self::RUNGS; ++$rung) {
            $decoded = self::at($source->size, $rung);

            if ($decoded->width >= $width && $decoded->height >= $height && $preservesGeometry($decoded)) {
                return $decoded->width . 'x' . $decoded->height;
            }
        }

        // Every rung below the full decode is too small or rounds the projection
        // somewhere else, so there is nothing to gain and the option would only
        // be noise in a stack trace.
        return null;
    }

    /**
     * What the source decodes to at one rung, the way libjpeg computes it.
     */
    public static function at(Size $source, int $rung): Size
    {
        return new Size(
            max(1, intdiv($source->width * $rung + self::RUNGS - 1, self::RUNGS)),
            max(1, intdiv($source->height * $rung + self::RUNGS - 1, self::RUNGS)),
        );
    }
}
