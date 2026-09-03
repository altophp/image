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

namespace Alto\Image\Operation;

use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Size;

/**
 * Fully resolved geometry handed to a driver.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Placement
{
    public function __construct(
        public int $scaleWidth,
        public int $scaleHeight,
        public ?int $cropWidth = null,
        public ?int $cropHeight = null,
        public ?int $cropX = null,
        public ?int $cropY = null,
        public int $padTop = 0,
        public int $padRight = 0,
        public int $padBottom = 0,
        public int $padLeft = 0,
    ) {
        if ($scaleWidth < 1 || $scaleHeight < 1) {
            throw new InvalidArgumentException(\sprintf('Placement scale must be positive, got %dx%d.', $scaleWidth, $scaleHeight));
        }

        if ((null === $cropWidth) !== (null === $cropHeight)) {
            throw new InvalidArgumentException('Placement crop needs both width and height.');
        }

        if (null !== $cropWidth && ($cropWidth < 1 || $cropHeight < 1)) {
            throw new InvalidArgumentException('Placement crop dimensions must be positive.');
        }

        if ((null === $cropX) !== (null === $cropY)) {
            throw new InvalidArgumentException('Placement crop offset needs both x and y, or neither for deferred focus.');
        }

        if (null !== $cropX && ($cropX < 0 || $cropY < 0)) {
            throw new InvalidArgumentException('Placement crop offsets cannot be negative.');
        }

        foreach (['top' => $padTop, 'right' => $padRight, 'bottom' => $padBottom, 'left' => $padLeft] as $side => $padding) {
            if ($padding < 0) {
                throw new InvalidArgumentException(\sprintf('Placement %s padding cannot be negative.', $side));
            }
        }
    }

    /**
     * A placement that scales to a size and does nothing else.
     */
    public static function scale(Size $to): self
    {
        return new self($to->width, $to->height);
    }

    public function outputWidth(): int
    {
        return ($this->cropWidth ?? $this->scaleWidth) + $this->padLeft + $this->padRight;
    }

    public function outputHeight(): int
    {
        return ($this->cropHeight ?? $this->scaleHeight) + $this->padTop + $this->padBottom;
    }

    public function output(): Size
    {
        return new Size($this->outputWidth(), $this->outputHeight());
    }

    public function hasCrop(): bool
    {
        return null !== $this->cropWidth;
    }

    public function hasPad(): bool
    {
        return 0 !== $this->padTop + $this->padRight + $this->padBottom + $this->padLeft;
    }

    /**
     * Whether this placement asks for a crop whose position the driver must choose.
     */
    public function cropIsDeferred(): bool
    {
        return $this->hasCrop() && null === $this->cropX;
    }

    /**
     * Whether executing this placement would return the source unchanged.
     */
    public function isNoop(Size $source): bool
    {
        return $this->scaleWidth === $source->width
            && $this->scaleHeight === $source->height
            && !$this->hasCrop()
            && !$this->hasPad();
    }
}
