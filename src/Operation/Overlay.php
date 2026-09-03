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
use Alto\Image\Internal\Fingerprint;
use Alto\Image\Metadata;

/**
 * Draws another image over the current image.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Overlay implements PortableOperationInterface
{
    /**
     * @param float $opacity 0.0 through 1.0
     * @param int   $margin  pixels between the overlay and the anchored edge
     */
    public function __construct(
        public string $path,
        public Anchor $gravity = Anchor::BottomRight,
        public float $opacity = 1.0,
        public int $margin = 0,
    ) {
        if ('' === $path) {
            throw new InvalidArgumentException('Overlay needs a path to the image to draw.');
        }

        if ($opacity < 0.0 || $opacity > 1.0) {
            throw new InvalidArgumentException(\sprintf('Overlay opacity falls in [0.0, 1.0], got %s.', $opacity));
        }

        if ($margin < 0) {
            throw new InvalidArgumentException(\sprintf('Overlay margin cannot be negative, got %d.', $margin));
        }
    }

    public function project(Metadata $input): Metadata
    {
        return $input;
    }

    public function __toString(): string
    {
        $parts = ['overlay=' . rawurlencode($this->path)];

        if (Anchor::BottomRight !== $this->gravity) {
            $parts[] = 'g:' . $this->gravity->value;
        }

        if (1.0 !== $this->opacity) {
            $parts[] = 'o:' . json_encode($this->opacity, \JSON_THROW_ON_ERROR);
        }

        if (0 !== $this->margin) {
            $parts[] = 'm:' . $this->margin;
        }

        return implode(',', $parts);
    }

    /**
     * Follows the same cheap, change-sensitive identity strategy as a file Source.
     */
    public function dependencySignature(): string
    {
        return (Fingerprint::stat())($this->path);
    }

    public static function parse(array $arguments): static
    {
        return new self(
            rawurldecode($arguments['0'] ?? ''),
            Anchor::from($arguments['g'] ?? 'bottom-right'),
            (float) ($arguments['o'] ?? '1'),
            (int) ($arguments['m'] ?? '0'),
        );
    }
}
