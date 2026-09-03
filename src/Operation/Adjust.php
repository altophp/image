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
 * Adjusts brightness, contrast, saturation, and gamma in one step.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Adjust implements PortableOperationInterface
{
    /**
     * @param int   $brightness -100 through 100, 0 leaves it alone
     * @param int   $contrast   -100 through 100, 0 leaves it alone
     * @param int   $saturation -100 through 100, 0 leaves it alone
     * @param float $gamma      0.1 through 10.0, 1.0 leaves it alone
     */
    public function __construct(
        public int $brightness = 0,
        public int $contrast = 0,
        public int $saturation = 0,
        public float $gamma = 1.0,
    ) {
        foreach (['brightness' => $brightness, 'contrast' => $contrast, 'saturation' => $saturation] as $name => $value) {
            if ($value < -100 || $value > 100) {
                throw new InvalidArgumentException(\sprintf('Adjust %s falls in [-100, 100], got %d.', $name, $value));
            }
        }

        if ($gamma < 0.1 || $gamma > 10.0) {
            throw new InvalidArgumentException(\sprintf('Adjust gamma falls in [0.1, 10.0], got %s.', $gamma));
        }
    }

    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function isNoop(): bool
    {
        return 0 === $this->brightness && 0 === $this->contrast && 0 === $this->saturation && 1.0 === $this->gamma;
    }

    public function __toString(): string
    {
        $parts = [];

        foreach (['b' => $this->brightness, 'c' => $this->contrast, 's' => $this->saturation] as $key => $value) {
            if (0 !== $value) {
                $parts[] = $key . ':' . $value;
            }
        }

        if (1.0 !== $this->gamma) {
            $parts[] = 'g:' . rtrim(rtrim(sprintf('%.4F', $this->gamma), '0'), '.');
        }

        return [] === $parts ? 'adjust' : 'adjust=' . implode(',', $parts);
    }

    public static function parse(array $arguments): static
    {
        return new self(
            (int) ($arguments['b'] ?? '0'),
            (int) ($arguments['c'] ?? '0'),
            (int) ($arguments['s'] ?? '0'),
            (float) ($arguments['g'] ?? '1'),
        );
    }
}
