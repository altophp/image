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

namespace Alto\Image\Driver;

use Alto\Image\Effort;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\MetadataPolicy;

/**
 * Immutable output format, quality, effort, and metadata settings.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Encoding implements \Stringable
{
    /**
     * @param Format|null $format   null keeps the source format
     * @param int|null    $quality  null takes the format's own default
     * @param int|null    $maxBytes a ceiling the encoder searches down to, at the cost of extra passes
     */
    public function __construct(
        public ?Format $format = null,
        public ?int $quality = null,
        public Effort $effort = Effort::Balanced,
        public MetadataPolicy $metadata = MetadataPolicy::Strip,
        public ?int $maxBytes = null,
        public bool $progressive = true,
        public bool $lossless = false,
    ) {
        if (null !== $quality && ($quality < 1 || $quality > 100)) {
            throw new InvalidArgumentException(\sprintf('Quality falls in [1, 100], got %d.', $quality));
        }

        if (null !== $maxBytes && $maxBytes < 1) {
            throw new InvalidArgumentException(\sprintf('A byte ceiling must be positive, got %d.', $maxBytes));
        }

        if (null !== $format && !$format->isLossy() && null !== $quality) {
            throw new InvalidArgumentException(\sprintf('%s is lossless and has no quality setting.', $format->value));
        }

        if (null !== $format && !$format->isLossy() && null !== $maxBytes) {
            throw new InvalidArgumentException(\sprintf('%s is lossless and cannot search a lossy byte ceiling.', $format->value));
        }

        if (null !== $format && Format::Jpeg !== $format && !$progressive) {
            throw new InvalidArgumentException('Progressive encoding is only configurable for JPEG.');
        }

        if ($lossless && null !== $quality) {
            throw new InvalidArgumentException('A lossless encoding has no quality setting.');
        }

        if ($lossless && null !== $format && !\in_array($format, [Format::Webp, Format::Avif, Format::Jxl], true)) {
            throw new InvalidArgumentException(\sprintf('Lossless mode is not configurable for %s.', $format->value));
        }
    }

    public function with(
        ?Format $format = null,
        ?int $quality = null,
        ?Effort $effort = null,
        ?MetadataPolicy $metadata = null,
        ?int $maxBytes = null,
        ?bool $progressive = null,
        ?bool $lossless = null,
    ): self {
        return new self(
            $format ?? $this->format,
            $quality ?? $this->quality,
            $effort ?? $this->effort,
            $metadata ?? $this->metadata,
            $maxBytes ?? $this->maxBytes,
            $progressive ?? $this->progressive,
            $lossless ?? $this->lossless,
        );
    }

    public function withoutMaxBytes(): self
    {
        return new self(
            $this->format,
            $this->quality,
            $this->effort,
            $this->metadata,
            null,
            $this->progressive,
            $this->lossless,
        );
    }

    public function withSourceFormat(): self
    {
        return new self(
            null,
            $this->quality,
            $this->effort,
            $this->metadata,
            $this->maxBytes,
            $this->progressive,
            $this->lossless,
        );
    }

    public function withDefaultQuality(): self
    {
        return new self(
            $this->format,
            null,
            $this->effort,
            $this->metadata,
            $this->maxBytes,
            $this->progressive,
            $this->lossless,
        );
    }

    /**
     * The same encoding with every question answered, given what the source is.
     */
    public function resolve(Format $source): self
    {
        $format = $this->format ?? $source;

        return new self(
            $format,
            $this->quality ?? ($format->isLossy() ? $format->defaultQuality() : null),
            $this->effort,
            $this->metadata,
            $this->maxBytes,
            $this->progressive,
            $this->lossless,
        );
    }

    public function formatOr(Format $source): Format
    {
        return $this->format ?? $source;
    }

    public function qualityFor(Format $format): int
    {
        return $this->quality ?? $format->defaultQuality();
    }

    /**
     * Whether the source bytes already satisfy this encoding.
     *
     * A pass-through requires an unchanged format and no explicit request to
     * recompress, cap bytes or switch to lossless encoding.
     */
    public function isPassThrough(Metadata $source): bool
    {
        // An explicit quality requests recompression because headers do not expose
        // the source encoding quality.
        if (null !== $this->quality || null !== $this->maxBytes || $this->lossless) {
            return false;
        }

        if ($this->formatOr($source->format) !== $source->format) {
            return false;
        }

        if (null !== $source->icc && !$this->metadata->keepsProfile()) {
            return false;
        }

        // Filtering tags is a rewrite. Only Keep can pass unknown metadata
        // through byte-for-byte; Copyright must first remove everything else.
        return !$source->hasMetadata || $this->metadata->keepsEverything();
    }

    public function __toString(): string
    {
        if (null === $this->format) {
            return 'source';
        }

        if (!$this->format->isLossy()) {
            return $this->format->value;
        }

        return \sprintf(
            '%s q%d%s%s',
            $this->format->value,
            $this->qualityFor($this->format),
            Effort::Balanced === $this->effort ? '' : ' ' . $this->effort->value,
            null === $this->maxBytes ? '' : ' <=' . $this->maxBytes . 'B',
        );
    }

    /**
     * The part of a cache key this encoding is responsible for.
     */
    public function signature(): string
    {
        return implode(',', [
            null === $this->format ? '~' : $this->format->value,
            (string) ($this->quality ?? '~'),
            $this->effort->value,
            $this->metadata->value,
            (string) ($this->maxBytes ?? '~'),
            $this->progressive ? 'p' : '-',
            $this->lossless ? 'l' : '-',
        ]);
    }
}
