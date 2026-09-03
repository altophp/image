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
use Alto\Image\Size;

/**
 * Adds padding around an image without scaling it.
 *
 * `extend=10` for all four sides, `extend=0,t:40,bg:ffffff` for one of them.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Extend implements PortableOperationInterface, Solvable
{
    public function __construct(
        public int $top = 0,
        public int $right = 0,
        public int $bottom = 0,
        public int $left = 0,
        public int $background = 0x00000000,
    ) {
        foreach (['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left] as $side => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(\sprintf('Extend cannot take %d pixels off the %s. Use crop for that.', -$value, $side));
            }
        }
    }

    public static function all(int $pixels, int $background = 0x00000000): self
    {
        return new self($pixels, $pixels, $pixels, $pixels, $background);
    }

    public function project(Metadata $input): Metadata
    {
        return $input->with(
            size: $this->solve($input->size)->output(),
            hasAlpha: $input->hasAlpha || (!Colour::isOpaque($this->background) && $this->hasPadding()),
        );
    }

    public function solve(Size $source): Placement
    {
        return new Placement(
            $source->width,
            $source->height,
            padTop: $this->top,
            padRight: $this->right,
            padBottom: $this->bottom,
            padLeft: $this->left,
        );
    }

    public function hasPadding(): bool
    {
        return 0 !== $this->top + $this->right + $this->bottom + $this->left;
    }

    public function __toString(): string
    {
        $uniform = $this->top === $this->right && $this->right === $this->bottom && $this->bottom === $this->left;
        $parts = ['extend=' . ($uniform ? $this->top : 0)];

        if (!$uniform) {
            foreach (['t' => $this->top, 'r' => $this->right, 'b' => $this->bottom, 'l' => $this->left] as $key => $value) {
                if (0 !== $value) {
                    $parts[] = $key . ':' . $value;
                }
            }
        }

        if (Colour::TRANSPARENT !== $this->background) {
            $parts[] = 'bg:' . Colour::format($this->background);
        }

        return implode(',', $parts);
    }

    public static function parse(array $arguments): static
    {
        $all = (int) ($arguments['0'] ?? '0');

        return new self(
            (int) ($arguments['t'] ?? $all),
            (int) ($arguments['r'] ?? $all),
            (int) ($arguments['b'] ?? $all),
            (int) ($arguments['l'] ?? $all),
            Colour::parse($arguments['bg'] ?? 'transparent'),
        );
    }
}
