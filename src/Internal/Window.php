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

use Alto\Image\Operation\Placement;
use Alto\Image\Size;

/**
 * Maps a resolved placement to the source and destination rectangles it needs.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Window
{
    /**
     * @param int $sourceX     where to start reading, in source pixels
     * @param int $width       what the read becomes, in output pixels
     * @param int $sourceWidth how much of the source is read
     */
    private function __construct(
        public int $sourceX,
        public int $sourceY,
        public int $sourceWidth,
        public int $sourceHeight,
        public int $width,
        public int $height,
    ) {}

    /**
     * @param int $cropX the crop offset in scaled coordinates, already resolved
     */
    public static function of(Placement $placement, Size $source, int $cropX, int $cropY): self
    {
        $width = $placement->cropWidth ?? $placement->scaleWidth;
        $height = $placement->cropHeight ?? $placement->scaleHeight;

        $ratioX = $source->width / max(1, $placement->scaleWidth);
        $ratioY = $source->height / max(1, $placement->scaleHeight);

        $sourceX = max(0, min($source->width - 1, (int) round($cropX * $ratioX)));
        $sourceY = max(0, min($source->height - 1, (int) round($cropY * $ratioY)));

        return new self(
            $sourceX,
            $sourceY,
            max(1, min($source->width - $sourceX, (int) round($width * $ratioX))),
            max(1, min($source->height - $sourceY, (int) round($height * $ratioY))),
            $width,
            $height,
        );
    }

    /**
     * Whether this reads the whole source at its own size, which is a copy.
     */
    public function isIdentity(): bool
    {
        return 0 === $this->sourceX
            && 0 === $this->sourceY
            && $this->sourceWidth === $this->width
            && $this->sourceHeight === $this->height;
    }
}
