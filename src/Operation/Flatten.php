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
use Alto\Image\Metadata;

/**
 * Composites an image onto an opaque background.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Flatten implements PortableOperationInterface
{
    public function __construct(public int $background = 0xFFFFFFFF) {}

    public function project(Metadata $input): Metadata
    {
        return $input->with(hasAlpha: !Colour::isOpaque($this->background) && $input->hasAlpha);
    }

    public function __toString(): string
    {
        return 'flatten=' . Colour::format($this->background);
    }

    public static function parse(array $arguments): static
    {
        return new self(Colour::parse($arguments['0'] ?? 'ffffff'));
    }
}
