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

namespace Alto\Image\Tests\Unit;

use Alto\Image\Metadata;
use Alto\Image\Operation\PortableOperationInterface;
use Alto\Image\Size;

/**
 * A third-party operation, in full, so the seam can be measured rather than described.
 *
 * Two methods and a string form. No registration, no base class, no compiler
 * pass: it becomes usable everywhere, including inside a transform string, by
 * being added to an array.
 */
final readonly class Doubling implements PortableOperationInterface
{
    public function project(Metadata $input): Metadata
    {
        return $input->with(size: new Size($input->width() * 2, $input->height() * 2));
    }

    public function __toString(): string
    {
        return 'double';
    }

    public static function parse(array $arguments): static
    {
        return new self();
    }
}
