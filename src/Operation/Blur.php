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
 * Applies a Gaussian blur with a requested standard deviation.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Blur implements PortableOperationInterface
{
    public function __construct(public float $sigma = 1.0)
    {
        if ($sigma <= 0.0 || !is_finite($sigma)) {
            throw new InvalidArgumentException(\sprintf('Blur sigma must be a positive number, got %s.', $sigma));
        }
    }

    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function __toString(): string
    {
        return 'blur=' . rtrim(rtrim(sprintf('%.4F', $this->sigma), '0'), '.');
    }

    public static function parse(array $arguments): static
    {
        return new self((float) ($arguments['0'] ?? '1'));
    }
}
