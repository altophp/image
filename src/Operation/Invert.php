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

use Alto\Image\Metadata;

/**
 * Inverts every colour channel, leaving alpha alone.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Invert implements PortableOperationInterface
{
    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function __toString(): string
    {
        return 'invert';
    }

    public static function parse(array $arguments): static
    {
        return new self();
    }
}
