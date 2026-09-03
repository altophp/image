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
 * An encoded image and the metadata describing what was produced.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Result implements \Stringable
{
    /**
     * @param list<string> $degradations one line per thing the driver could not do exactly
     * @param float        $duration     seconds spent inside the driver
     * @param bool         $copied       whether the driver recognised a noop and copied bytes
     */
    public function __construct(
        public Metadata $metadata,
        public string $bytes,
        public string $driver = '',
        public array $degradations = [],
        public float $duration = 0.0,
        public ?string $path = null,
        public bool $copied = false,
    ) {
        if (!is_finite($duration) || $duration < 0.0) {
            throw new InvalidArgumentException(\sprintf('A render duration must be finite and non-negative, got %s.', $duration));
        }

        if (null !== $metadata->bytes && $metadata->bytes !== \strlen($bytes)) {
            throw new InvalidArgumentException(\sprintf(
                'Result metadata says %d bytes, but the result holds %d.',
                $metadata->bytes,
                \strlen($bytes),
            ));
        }
    }

    public function size(): Size
    {
        return $this->metadata->size;
    }

    public function format(): Format
    {
        return $this->metadata->format;
    }

    public function length(): int
    {
        return \strlen($this->bytes);
    }

    public function isExact(): bool
    {
        return [] === $this->degradations;
    }

    public function withPath(string $path): self
    {
        return new self(
            $this->metadata,
            $this->bytes,
            $this->driver,
            $this->degradations,
            $this->duration,
            $path,
            $this->copied,
        );
    }

    public function dataUri(): string
    {
        return 'data:' . $this->metadata->format->mime() . ';base64,' . base64_encode($this->bytes);
    }

    public function __toString(): string
    {
        return \sprintf(
            '%s %s in %.1f ms%s%s',
            $this->metadata,
            self::humanBytes($this->length()),
            $this->duration * 1000,
            $this->copied ? ' (copied)' : '',
            [] === $this->degradations ? '' : ' [' . implode('; ', $this->degradations) . ']',
        );
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        return $bytes < 1024 * 1024
            ? \sprintf('%.1f KB', $bytes / 1024)
            : \sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}
