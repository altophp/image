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

use Alto\Image\Anchor;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\FocalPoint;
use Alto\Image\Focus;
use Alto\Image\Metadata;
use Alto\Image\Size;

/**
 * Extracts a clamped rectangle without scaling the source.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Crop implements PortableOperationInterface, Solvable
{
    public function __construct(
        public int $width,
        public int $height,
        public Anchor|Focus|FocalPoint $gravity = Anchor::Center,
        public ?int $x = null,
        public ?int $y = null,
    ) {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException(\sprintf('Crop needs a positive box, got %dx%d.', $width, $height));
        }

        if ((null === $x) !== (null === $y)) {
            throw new InvalidArgumentException('Crop takes both x and y or neither, because half a coordinate is not a position.');
        }

        if (null !== $x && Anchor::Center !== $gravity) {
            throw new InvalidArgumentException('Crop takes either explicit x/y coordinates or gravity, not both.');
        }
    }

    public function project(Metadata $input): Metadata
    {
        return $input->with(size: $this->solve($input->size)->output());
    }

    public function solve(Size $source): Placement
    {
        $box = new Size(min($this->width, $source->width), min($this->height, $source->height));

        // Same rule as Resize: a rectangle that covers the source is not a crop.
        if ($box->equals($source)) {
            return Placement::scale($source);
        }

        if (null !== $this->x && null !== $this->y) {
            return new Placement(
                $source->width,
                $source->height,
                $box->width,
                $box->height,
                max(0, min($source->width - $box->width, $this->x)),
                max(0, min($source->height - $box->height, $this->y)),
            );
        }

        [$x, $y] = $this->gravity instanceof Focus ? [null, null] : $this->gravity->offsetIn($source, $box);

        return new Placement($source->width, $source->height, $box->width, $box->height, $x, $y);
    }

    public function __toString(): string
    {
        $parts = ['crop=' . $this->width . 'x' . $this->height];

        if (null !== $this->x && null !== $this->y) {
            $parts[] = 'x:' . $this->x;
            $parts[] = 'y:' . $this->y;

            return implode(',', $parts);
        }

        if (Anchor::Center !== $this->gravity) {
            $parts[] = 'g:' . ($this->gravity instanceof Anchor || $this->gravity instanceof Focus
                ? $this->gravity->value
                : (string) $this->gravity);
        }

        return implode(',', $parts);
    }

    public static function parse(array $arguments): static
    {
        [$width, $height] = array_pad(explode('x', $arguments['0'] ?? ''), 2, '');

        if ('' === $width || '' === $height) {
            throw new InvalidArgumentException(\sprintf('Crop reads as "crop=<width>x<height>", got "crop=%s".', $arguments['0'] ?? ''));
        }

        $gravity = $arguments['g'] ?? 'center';

        return new self(
            (int) $width,
            (int) $height,
            Focus::tryFrom($gravity) ?? Anchor::tryFrom($gravity) ?? FocalPoint::parse($gravity),
            isset($arguments['x']) ? (int) $arguments['x'] : null,
            isset($arguments['y']) ? (int) $arguments['y'] : null,
        );
    }
}
