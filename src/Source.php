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
use Alto\Image\Exception\SourceNotFoundException;
use Alto\Image\Internal\Fingerprint;
use Alto\Image\Internal\HeaderReader;

/**
 * An image source backed by a file, byte string, or stream.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Source implements \Stringable
{
    private ?Metadata $metadata = null;

    private ?string $head = null;

    private ?string $fingerprint = null;

    /**
     * @param resource|null            $stream
     * @param \Closure(string): string $identify how this source names itself to a cache key
     */
    private function __construct(
        public readonly ?string $path,
        private ?string $buffer,
        private $stream,
        public readonly string $name,
        private readonly \Closure $identify,
    ) {}

    /**
     * Accepts what a caller already has, which is usually a path.
     */
    public static function of(string|self $source): self
    {
        return $source instanceof self ? $source : self::file($source);
    }

    public static function file(string $path, ?\Closure $identify = null): self
    {
        return new self($path, null, null, pathinfo($path, \PATHINFO_FILENAME), $identify ?? Fingerprint::stat());
    }

    /**
     * @param string $bytes the encoded image, not a path
     */
    public static function bytes(string $bytes, string $name = 'image'): self
    {
        if ('' === $bytes) {
            throw new InvalidArgumentException('A source cannot be empty. Pass the encoded image bytes, not a path.');
        }

        return new self(null, $bytes, null, $name, Fingerprint::stat());
    }

    /**
     * @param resource $stream
     */
    public static function stream($stream, string $name = 'image'): self
    {
        if (!\is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new InvalidArgumentException('Source::stream() takes an open stream resource.');
        }

        return new self(null, null, $stream, $name, Fingerprint::stat());
    }

    /**
     * A copy that identifies itself differently, for a deployment where a stale
     * derivative costs more than a hash of every source.
     */
    public function identifiedBy(\Closure $identify): self
    {
        if (null === $this->path) {
            throw new InvalidArgumentException('Only a file Source can replace its path fingerprint. Byte and stream sources already hash their contents.');
        }

        return new self($this->path, $this->buffer, $this->stream, $this->name, $identify);
    }

    public function exists(): bool
    {
        if (null === $this->path) {
            return null !== $this->buffer || \is_resource($this->stream);
        }

        return is_file($this->path) && is_readable($this->path);
    }

    /**
     * The first bytes, and never more than were asked for.
     *
     * @throws SourceNotFoundException
     */
    public function head(int $length = 4096): string
    {
        $length = max(1, $length);

        if (null !== $this->head && \strlen($this->head) >= $length) {
            return substr($this->head, 0, $length);
        }

        $this->head = $this->readHead($length);

        return $this->head;
    }

    /**
     * The last bytes, for the trailer check that catches a truncated file.
     *
     * @throws SourceNotFoundException
     */
    public function tail(int $length = 16): string
    {
        $length = max(1, $length);

        if (null !== $this->buffer) {
            return substr($this->buffer, -$length);
        }

        if (null !== $this->stream) {
            $this->buffer = $this->drain();

            return substr($this->buffer, -$length);
        }

        if (!$this->exists()) {
            throw SourceNotFoundException::at((string) $this->path);
        }

        $handle = @fopen((string) $this->path, 'rb');

        if (false === $handle) {
            // @codeCoverageIgnoreStart
            throw SourceNotFoundException::at((string) $this->path);
            // @codeCoverageIgnoreEnd
        }

        try {
            if (0 !== fseek($handle, -$length, \SEEK_END)) {
                // @codeCoverageIgnoreStart
                rewind($handle);
                // @codeCoverageIgnoreEnd
            }

            $tail = fread($handle, $length);
        } finally {
            fclose($handle);
        }

        return false === $tail ? '' : $tail;
    }

    /**
     * Every byte. Drivers call this; probing never does.
     *
     * @throws SourceNotFoundException
     */
    public function contents(): string
    {
        if (null !== $this->buffer) {
            return $this->buffer;
        }

        if (null !== $this->stream) {
            $this->buffer = $this->drain();

            return $this->buffer;
        }

        if (!$this->exists()) {
            throw SourceNotFoundException::at((string) $this->path);
        }

        $contents = @file_get_contents((string) $this->path);

        if (false === $contents) {
            // @codeCoverageIgnoreStart
            throw SourceNotFoundException::at((string) $this->path);
            // @codeCoverageIgnoreEnd
        }

        return $this->buffer = $contents;
    }

    /**
     * The encoded byte count, from a stat where there is one.
     */
    public function length(): ?int
    {
        if (null !== $this->buffer) {
            return \strlen($this->buffer);
        }

        if (null === $this->path) {
            return null;
        }

        $size = @filesize($this->path);

        return false === $size ? null : $size;
    }

    /**
     * What this image is, read from the header and cached.
     *
     * Reads larger header segments only when the initial four kilobytes are
     * insufficient, typically for JPEG files with large EXIF thumbnails.
     */
    public function metadata(): Metadata
    {
        if (null !== $this->metadata) {
            return $this->metadata;
        }

        $length = $this->length();
        $read = 0;

        foreach (HeaderReader::HEADS as $wanted) {
            $head = $this->head($wanted);
            $read = \strlen($head);
            $metadata = HeaderReader::tryRead($head, $length ?? $read);

            if (null !== $metadata) {
                return $this->metadata = $metadata;
            }

            if ($read < $wanted) {
                break;
            }
        }

        return $this->metadata = HeaderReader::read($this->head(HeaderReader::HEAD), $this->origin(), $length);
    }

    /**
     * The stable identity of these bytes, for a cache key.
     */
    public function signature(): string
    {
        if (null !== $this->fingerprint) {
            return $this->fingerprint;
        }

        if (null !== $this->path) {
            return $this->fingerprint = ($this->identify)($this->path);
        }

        return $this->fingerprint = Fingerprint::ofBytes($this->contents());
    }

    /**
     * How to name this source in an error message.
     */
    public function origin(): string
    {
        return $this->path ?? \sprintf('%s (in memory)', $this->name);
    }

    public function __toString(): string
    {
        return $this->origin();
    }

    /**
     * @param int<1, max> $length
     */
    private function readHead(int $length): string
    {
        if (null !== $this->buffer) {
            return substr($this->buffer, 0, $length);
        }

        if (null !== $this->stream) {
            $this->buffer = $this->drain();

            return substr($this->buffer, 0, $length);
        }

        if (!$this->exists()) {
            throw SourceNotFoundException::at((string) $this->path);
        }

        $handle = @fopen((string) $this->path, 'rb');

        if (false === $handle) {
            // @codeCoverageIgnoreStart
            throw SourceNotFoundException::at((string) $this->path);
            // @codeCoverageIgnoreEnd
        }

        try {
            $head = fread($handle, $length);
        } finally {
            fclose($handle);
        }

        return false === $head ? '' : $head;
    }

    /**
     * A stream is read once and kept, because it may not rewind and a driver
     * will want the same bytes the probe saw.
     */
    private function drain(): string
    {
        $stream = $this->stream;

        if (!\is_resource($stream)) {
            throw new InvalidArgumentException('The stream backing this source has been closed.');
        }

        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        }

        $contents = stream_get_contents($stream);

        return false === $contents ? '' : $contents;
    }
}
