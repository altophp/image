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

namespace Alto\Image;

use Alto\Image\Exception\InvalidArgumentException;

/**
 * Immutable image metadata read from a header or projected by a transform.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Metadata
{
    public const string SRGB = 'srgb';
    public const string CMYK = 'cmyk';
    public const string GRAY = 'gray';
    public const string UNKNOWN = 'unknown';

    /**
     * @param int         $orientation the EXIF value, 1 through 8
     * @param string      $colourSpace one of the class constants
     * @param string|null $icc         the profile description, never its bytes
     * @param int|null    $bytes        the encoded size, when it is known
     * @param bool        $hasMetadata whether the file carries EXIF, IPTC or XMP worth stripping
     */
    public function __construct(
        public Size $size,
        public Format $format,
        public bool $hasAlpha = false,
        public int $frames = 1,
        public int $orientation = 1,
        public string $colourSpace = self::SRGB,
        public ?string $icc = null,
        public ?int $bytes = null,
        public bool $hasMetadata = false,
    ) {
        if ($frames < 1) {
            throw new InvalidArgumentException(\sprintf('An image has at least one frame, got %d.', $frames));
        }

        if ($orientation < 1 || $orientation > 8) {
            throw new InvalidArgumentException(\sprintf('EXIF orientation falls in [1, 8], got %d.', $orientation));
        }

        if (!\in_array($colourSpace, [self::SRGB, self::CMYK, self::GRAY, self::UNKNOWN], true)) {
            throw new InvalidArgumentException(\sprintf('Unknown colour space "%s".', $colourSpace));
        }

        if (null !== $bytes && $bytes < 0) {
            throw new InvalidArgumentException(\sprintf('Encoded byte size cannot be negative, got %d.', $bytes));
        }
    }

    public function width(): int
    {
        return $this->size->width;
    }

    public function height(): int
    {
        return $this->size->height;
    }

    public function isAnimated(): bool
    {
        return $this->frames > 1;
    }

    /**
     * Whether the EXIF orientation exchanges the two axes.
     *
     * Values 5 through 8 are the quarter turns, and a projection that ignores
     * them reports a portrait photograph as landscape.
     */
    public function isTransposed(): bool
    {
        return $this->orientation >= 5 && $this->orientation <= 8;
    }

    /**
     * The size as a viewer sees it, with the EXIF orientation applied.
     */
    public function displaySize(): Size
    {
        return $this->isTransposed() ? $this->size->transposed() : $this->size;
    }

    /**
     * The same image as a viewer sees it: axes exchanged if the EXIF value asks
     * for a quarter turn, and the orientation spent.
     *
     * A Plan calls this once before projecting anything, which is what lets the
     * transform string stay free of an `orient` step it would have to carry on
     * every URL. Orientation belongs to the source, and the source fingerprint
     * already covers it.
     */
    public function oriented(): self
    {
        if (1 === $this->orientation) {
            return $this;
        }

        return new self(
            $this->displaySize(),
            $this->format,
            $this->hasAlpha,
            $this->frames,
            1,
            $this->colourSpace,
            $this->icc,
            $this->bytes,
            $this->hasMetadata,
        );
    }

    /**
     * A copy with some fields replaced.
     *
     * Null leaves a field unchanged. The explicit without methods clear nullable
     * metadata without putting an implementation sentinel in this public API.
     */
    public function with(
        ?Size $size = null,
        ?Format $format = null,
        ?bool $hasAlpha = null,
        ?int $frames = null,
        ?int $orientation = null,
        ?string $colourSpace = null,
        ?string $icc = null,
        ?int $bytes = null,
        ?bool $hasMetadata = null,
    ): self {
        return new self(
            $size ?? $this->size,
            $format ?? $this->format,
            $hasAlpha ?? $this->hasAlpha,
            $frames ?? $this->frames,
            $orientation ?? $this->orientation,
            $colourSpace ?? $this->colourSpace,
            $icc ?? $this->icc,
            $bytes ?? $this->bytes,
            $hasMetadata ?? $this->hasMetadata,
        );
    }

    public function withoutIcc(): self
    {
        return new self(
            $this->size,
            $this->format,
            $this->hasAlpha,
            $this->frames,
            $this->orientation,
            $this->colourSpace,
            null,
            $this->bytes,
            $this->hasMetadata,
        );
    }

    public function withoutBytes(): self
    {
        return new self(
            $this->size,
            $this->format,
            $this->hasAlpha,
            $this->frames,
            $this->orientation,
            $this->colourSpace,
            $this->icc,
            null,
            $this->hasMetadata,
        );
    }

    public function __toString(): string
    {
        return \sprintf(
            '%s %s%s%s',
            $this->format->value,
            $this->size,
            $this->hasAlpha ? ' alpha' : '',
            $this->isAnimated() ? ' ' . $this->frames . 'f' : '',
        );
    }
}
