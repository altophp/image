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
use Alto\Image\Metadata;

/**
 * Mirrors an image along its horizontal or vertical axis.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Flip implements PortableOperationInterface
{
    private function __construct(public bool $vertical) {}

    public static function horizontal(): self
    {
        return new self(false);
    }

    public static function vertical(): self
    {
        return new self(true);
    }

    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function __toString(): string
    {
        return 'flip=' . ($this->vertical ? 'v' : 'h');
    }

    public static function parse(array $arguments): static
    {
        return new self(match ($arguments['0'] ?? 'h') {
            'h', 'horizontal', 'x' => false,
            'v', 'vertical', 'y' => true,
            default => throw new InvalidArgumentException(\sprintf(
                'Flip reads as "flip=h" or "flip=v", got "flip=%s".',
                $arguments['0'] ?? '',
            )),
        });
    }
}
