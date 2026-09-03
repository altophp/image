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

use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Exception\LimitExceededException;

/**
 * Safety limits enforced before decoding and encoding images.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Limits
{
    /**
     * @param int  $maxPixels    the source pixel count ceiling, checked from the header
     * @param int  $maxDimension the per-axis ceiling, which catches the 1x2000000000 shapes
     * @param int  $maxFrames    the animation ceiling
     * @param int  $maxBytes     the encoded source size ceiling
     * @param bool $strict       whether outputs are checked as well as sources
     */
    public function __construct(
        public int $maxPixels = 50_000_000,
        public int $maxDimension = 32_768,
        public int $maxFrames = 512,
        public int $maxBytes = 256 * 1024 * 1024,
        public FailOn $failOn = FailOn::Truncated,
        public bool $strict = true,
    ) {
        foreach (['maxPixels' => $maxPixels, 'maxDimension' => $maxDimension, 'maxFrames' => $maxFrames, 'maxBytes' => $maxBytes] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(\sprintf('%s must be positive, got %d.', $name, $value));
            }
        }
    }

    /**
     * A policy that checks nothing, for a corpus you produced yourself.
     */
    public static function none(): self
    {
        return new self(\PHP_INT_MAX, \PHP_INT_MAX, \PHP_INT_MAX, \PHP_INT_MAX, FailOn::None, false);
    }

    /**
     * @throws LimitExceededException
     */
    public function check(Metadata $metadata, string $origin): void
    {
        $size = $metadata->size;

        if ($size->width > $this->maxDimension || $size->height > $this->maxDimension) {
            throw LimitExceededException::dimension($size, $this->maxDimension, $origin);
        }

        if ($size->pixels() > $this->maxPixels) {
            throw LimitExceededException::pixels($size, $this->maxPixels, $origin);
        }

        if ($metadata->frames > $this->maxFrames) {
            throw LimitExceededException::frames($metadata->frames, $this->maxFrames, $origin);
        }

        if (null !== $metadata->bytes && $metadata->bytes > $this->maxBytes) {
            throw LimitExceededException::bytes($metadata->bytes, $this->maxBytes, $origin);
        }
    }

    /**
     * Whether this policy accepts a file that does not end the way its format ends.
     *
     * Enforced above the drivers, from eight bytes off the end, because no
     * decoder will tell you: libgd suppresses libjpeg's warnings and ImageMagick
     * reconstructs what it can. Under the default policy a file cut short is an
     * exception; under FailOn::None it is whatever rows survived.
     *
     * @throws CorruptImageException
     */
    public function checkComplete(Metadata $metadata, string $tail, string $origin): void
    {
        $trailer = $metadata->format->trailer();

        if (FailOn::None === $this->failOn || null === $trailer || str_ends_with($tail, $trailer)) {
            return;
        }

        throw CorruptImageException::truncated($origin, $metadata->format->value, $this->failOn->value);
    }

    /**
     * Checks projected output metadata against the configured limits.
     *
     * Skipped entirely when strict is off, because an output you asked for by
     * name is not an attack on yourself.
     *
     * @throws LimitExceededException
     */
    public function checkOutput(Metadata $metadata, string $origin): void
    {
        if ($this->strict) {
            $this->check($metadata->withoutBytes(), $origin);
        }
    }
}
