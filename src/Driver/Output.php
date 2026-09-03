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

use Alto\Image\Metadata;
use Alto\Image\Transform;

/**
 * One requested output combining a transform and encoding settings.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Output implements \Stringable
{
    public function __construct(
        public Transform $transform,
        public Encoding $encoding,
    ) {}

    public static function new(): self
    {
        return new self(Transform::new(), new Encoding());
    }

    public function with(?Transform $transform = null, ?Encoding $encoding = null): self
    {
        return new self($transform ?? $this->transform, $encoding ?? $this->encoding);
    }

    public function project(Metadata $source): Metadata
    {
        return $this->encoded($this->transform->project($source));
    }

    /**
     * Projects metadata until the first operation that cannot be measured.
     */
    public function estimate(Metadata $source): Metadata
    {
        return $this->encoded($this->transform->estimate($source));
    }

    /**
     * Applies the format and metadata policy without pretending stripped state
     * survives into the output.
     */
    private function encoded(Metadata $projected): Metadata
    {
        $format = $this->encoding->formatOr($projected->format);

        return $this->encoding->metadata->project($projected->with(
            format: $format,
            hasAlpha: $projected->hasAlpha && $format->supportsAlpha(),
        ))->withoutBytes();
    }

    /**
     * The part of a cache key this output is responsible for. The source
     * contributes the rest.
     */
    public function signature(): string
    {
        return $this->transform->signature() . '#' . $this->encoding->signature();
    }

    public function __toString(): string
    {
        $transform = (string) $this->transform;

        return ('' === $transform ? 'as-is' : $transform) . ' ' . $this->encoding;
    }
}
