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
 * Rotates an image and expands its canvas to contain the result.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Rotate implements PortableOperationInterface
{
    public float $degrees;

    public function __construct(float $degrees, public int $background = 0x00000000)
    {
        if (!is_finite($degrees)) {
            throw new InvalidArgumentException('Rotate needs a finite angle.');
        }

        $this->degrees = fmod(fmod($degrees, 360.0) + 360.0, 360.0);
    }

    public function project(Metadata $input): Metadata
    {
        return $input->with(
            size: $this->boundingBox($input->size),
            hasAlpha: $input->hasAlpha || (!$this->isQuarterTurn() && !Colour::isOpaque($this->background)),
        );
    }

    public function isQuarterTurn(): bool
    {
        return 0.0 === fmod($this->degrees, 90.0);
    }

    public function boundingBox(Size $source): Size
    {
        if ($this->isQuarterTurn()) {
            return 0.0 === fmod($this->degrees, 180.0) ? $source : $source->transposed();
        }

        $radians = deg2rad($this->degrees);
        $cos = abs(cos($radians));
        $sin = abs(sin($radians));

        // Ceil prevents clipped corners and matches GD's bounding box. Imagick
        // may return a box up to two pixels larger and conforms it in its pipeline.
        return new Size(
            max(1, (int) ceil($source->width * $cos + $source->height * $sin)),
            max(1, (int) ceil($source->width * $sin + $source->height * $cos)),
        );
    }

    public function __toString(): string
    {
        $parts = ['rotate=' . rtrim(rtrim(sprintf('%.4F', $this->degrees), '0'), '.')];

        if (Colour::TRANSPARENT !== $this->background) {
            $parts[] = 'bg:' . Colour::format($this->background);
        }

        return implode(',', $parts);
    }

    public static function parse(array $arguments): static
    {
        return new self(
            (float) ($arguments['0'] ?? '0'),
            Colour::parse($arguments['bg'] ?? 'transparent'),
        );
    }
}
