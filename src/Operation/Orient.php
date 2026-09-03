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
 * Applies an image's orientation metadata to its pixels.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Orient implements PortableOperationInterface
{
    public function project(Metadata $input): Metadata
    {
        return $input->oriented();
    }

    public function __toString(): string
    {
        return 'orient';
    }

    public static function parse(array $arguments): static
    {
        return new self();
    }
}
