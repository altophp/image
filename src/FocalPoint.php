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
 * A point of interest in normalised coordinates from the top-left origin.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FocalPoint implements \Stringable
{
    /**
     * @param float $x 0.0 is the left edge, 1.0 the right
     * @param float $y 0.0 is the top edge, 1.0 the bottom
     */
    public function __construct(
        public float $x,
        public float $y,
    ) {
        if (!is_finite($x) || !is_finite($y) || $x < 0.0 || $x > 1.0 || $y < 0.0 || $y > 1.0) {
            throw new InvalidArgumentException(\sprintf(
                'A focal point is normalised, so both axes must fall in [0.0, 1.0]. Got (%s, %s).',
                self::number($x),
                self::number($y),
            ));
        }
    }

    /**
     * Reads the "0.5x0.33" form.
     */
    public static function parse(string $value): self
    {
        $parts = explode('x', trim($value));

        if (2 !== \count($parts) || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            throw new InvalidArgumentException(\sprintf(
                'A focal point reads as "<x>x<y>", both normalised. Got "%s".',
                $value,
            ));
        }

        return new self((float) $parts[0], (float) $parts[1]);
    }

    /**
     * Where the inner box lands when this point is centered in it, clamped to the outer box.
     *
     * @return array{int, int}
     */
    public function offsetIn(Size $outer, Size $inner): array
    {
        return [
            max(0, min($outer->width - $inner->width, (int) round($this->x * $outer->width - $inner->width / 2))),
            max(0, min($outer->height - $inner->height, (int) round($this->y * $outer->height - $inner->height / 2))),
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->x, \JSON_THROW_ON_ERROR) . 'x' . json_encode($this->y, \JSON_THROW_ON_ERROR);
    }

    private static function number(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NAN',
            \INF === $value => 'INF',
            -\INF === $value => '-INF',
            default => (string) $value,
        };
    }
}
