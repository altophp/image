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
 * Replaces image regions with averaged square blocks.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Pixelate implements PortableOperationInterface
{
    public function __construct(public int $size = 8)
    {
        if ($size < 2) {
            throw new InvalidArgumentException(\sprintf('Pixelate needs a block of at least 2 pixels, got %d.', $size));
        }
    }

    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function __toString(): string
    {
        return 'pixelate=' . $this->size;
    }

    public static function parse(array $arguments): static
    {
        return new self((int) ($arguments['0'] ?? '8'));
    }
}
