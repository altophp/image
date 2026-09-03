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

use Alto\Image\Colour;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Metadata;

/**
 * Maps the image onto one hue, keeping the luminance.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Tint implements PortableOperationInterface
{
    /**
     * @param float $strength how far towards the tint to move, 0.0 through 1.0
     */
    public function __construct(
        public int $colour = 0xFF000000,
        public float $strength = 1.0,
    ) {
        if ($strength < 0.0 || $strength > 1.0) {
            throw new InvalidArgumentException(\sprintf('Tint strength falls in [0.0, 1.0], got %s.', $strength));
        }
    }

    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function __toString(): string
    {
        $parts = ['tint=' . Colour::format($this->colour)];

        if (1.0 !== $this->strength) {
            $parts[] = 'o:' . json_encode($this->strength, \JSON_THROW_ON_ERROR);
        }

        return implode(',', $parts);
    }

    public static function parse(array $arguments): static
    {
        return new self(
            Colour::parse($arguments['0'] ?? 'black'),
            (float) ($arguments['o'] ?? '1'),
        );
    }
}
