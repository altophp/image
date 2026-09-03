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
 * Applies an unsharp mask with configurable radius and amount.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Sharpen implements PortableOperationInterface
{
    /**
     * @param float $amount how much of the difference to add back, 0.0 through 5.0
     */
    public function __construct(
        public float $sigma = 1.0,
        public float $amount = 1.0,
    ) {
        if ($sigma <= 0.0 || !is_finite($sigma)) {
            throw new InvalidArgumentException(\sprintf('Sharpen sigma must be a positive number, got %s.', $sigma));
        }

        if ($amount < 0.0 || $amount > 5.0) {
            throw new InvalidArgumentException(\sprintf('Sharpen amount falls in [0.0, 5.0], got %s.', $amount));
        }
    }

    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function __toString(): string
    {
        $parts = ['sharpen=' . rtrim(rtrim(sprintf('%.4F', $this->sigma), '0'), '.')];

        if (1.0 !== $this->amount) {
            $parts[] = 'a:' . rtrim(rtrim(sprintf('%.4F', $this->amount), '0'), '.');
        }

        return implode(',', $parts);
    }

    public static function parse(array $arguments): static
    {
        return new self(
            (float) ($arguments['0'] ?? '1'),
            (float) ($arguments['a'] ?? '1'),
        );
    }
}
