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

namespace Alto\Image;

use Alto\Image\Exception\InvalidArgumentException;

/**
 * Immutable positive pixel dimensions.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Size implements \Stringable
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException(\sprintf('An image size must be positive, got %dx%d.', $width, $height));
        }
    }

    /**
     * Scales both axes by one factor, never below one pixel.
     */
    public function scaledBy(float $factor): self
    {
        return new self(
            max(1, (int) round($this->width * $factor)),
            max(1, (int) round($this->height * $factor)),
        );
    }

    public function ratio(): float
    {
        return $this->width / $this->height;
    }

    public function pixels(): int
    {
        return $this->width * $this->height;
    }

    public function equals(self $other): bool
    {
        return $this->width === $other->width && $this->height === $other->height;
    }

    /**
     * The size with its axes exchanged, which is what a quarter turn produces.
     */
    public function transposed(): self
    {
        return new self($this->height, $this->width);
    }

    public function __toString(): string
    {
        return $this->width . 'x' . $this->height;
    }
}
