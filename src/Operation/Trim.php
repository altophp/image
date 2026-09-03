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
 * Removes a pixel-dependent uniform border from an image.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Trim implements PortableOperationInterface
{
    /**
     * @param int      $threshold  per-channel tolerance, 0 through 255
     * @param int|null $background the colour to trim, or null to read the corner pixel
     */
    public function __construct(
        public int $threshold = 10,
        public ?int $background = null,
    ) {
        if ($threshold < 0 || $threshold > 255) {
            throw new InvalidArgumentException(\sprintf('Trim threshold is a channel tolerance in [0, 255], got %d.', $threshold));
        }
    }

    /**
     * The upper bound, which is the source. A trim only ever removes.
     */
    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function __toString(): string
    {
        $parts = ['trim=' . $this->threshold];

        if (null !== $this->background) {
            $parts[] = 'bg:' . Colour::format($this->background);
        }

        return implode(',', $parts);
    }

    public static function parse(array $arguments): static
    {
        return new self(
            (int) ($arguments['0'] ?? '10'),
            isset($arguments['bg']) ? Colour::parse($arguments['bg']) : null,
        );
    }
}
