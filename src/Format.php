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
 * A supported image format and its encoding characteristics.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum Format: string
{
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Webp = 'webp';
    case Avif = 'avif';
    case Jxl = 'jxl';
    case Heic = 'heic';
    case Tiff = 'tiff';
    case Gif = 'gif';
    case Bmp = 'bmp';
    case Svg = 'svg';

    /**
     * Resolves a format from an extension, a mime type or its own name.
     *
     * @throws InvalidArgumentException when nothing matches
     */
    public static function of(string $value): self
    {
        $normalised = strtolower(ltrim(trim($value), '.'));

        return self::tryFrom($normalised)
            ?? self::tryFromExtension($normalised)
            ?? self::tryFromMime($normalised)
            ?? throw new InvalidArgumentException(\sprintf(
                'Unknown image format "%s". Known: %s.',
                $value,
                implode(', ', array_column(self::cases(), 'value')),
            ));
    }

    public static function tryFromExtension(string $extension): ?self
    {
        return match (strtolower(ltrim(trim($extension), '.'))) {
            'jpg', 'jpeg', 'jpe', 'jfif' => self::Jpeg,
            'png' => self::Png,
            'webp' => self::Webp,
            'avif', 'avifs' => self::Avif,
            'jxl' => self::Jxl,
            'heic', 'heif', 'hif' => self::Heic,
            'tif', 'tiff' => self::Tiff,
            'gif' => self::Gif,
            'bmp', 'dib' => self::Bmp,
            'svg', 'svgz' => self::Svg,
            default => null,
        };
    }

    public static function tryFromMime(string $mime): ?self
    {
        return match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => self::Jpeg,
            'image/png', 'image/x-png' => self::Png,
            'image/webp' => self::Webp,
            'image/avif', 'image/avif-sequence' => self::Avif,
            'image/jxl' => self::Jxl,
            'image/heic', 'image/heif', 'image/heic-sequence' => self::Heic,
            'image/tiff' => self::Tiff,
            'image/gif' => self::Gif,
            'image/bmp', 'image/x-ms-bmp' => self::Bmp,
            'image/svg+xml' => self::Svg,
            default => null,
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Webp => 'image/webp',
            self::Avif => 'image/avif',
            self::Jxl => 'image/jxl',
            self::Heic => 'image/heic',
            self::Tiff => 'image/tiff',
            self::Gif => 'image/gif',
            self::Bmp => 'image/bmp',
            self::Svg => 'image/svg+xml',
        };
    }

    /**
     * The extension to write, without a leading dot.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Tiff => 'tif',
            default => $this->value,
        };
    }

    /**
     * The bytes a complete file of this format ends with, if it ends with any.
     *
     * Trailer bytes detect truncated files before decoding. Some decoders recover
     * partial JPEG data without reporting the missing tail.
     */
    public function trailer(): ?string
    {
        return match ($this) {
            self::Jpeg => "\xFF\xD9",
            self::Png => "IEND\xAE\x42\x60\x82",
            self::Gif => "\x3B",
            default => null,
        };
    }

    public function supportsAlpha(): bool
    {
        return match ($this) {
            self::Jpeg, self::Bmp => false,
            default => true,
        };
    }

    /**
     * Whether the format is vector-based.
     *
     * SVG is here so that a source can be probed, planned and named without any
     * driver in this package being able to decode it. Nothing rasterises one;
     * the refusal comes from the driver, which knows what to tell you to install.
     */
    public function isVector(): bool
    {
        return self::Svg === $this;
    }

    public function supportsAnimation(): bool
    {
        return match ($this) {
            self::Gif, self::Webp, self::Avif, self::Jxl, self::Heic => true,
            default => false,
        };
    }

    /**
     * Whether quality is a meaningful knob for this format.
     */
    public function isLossy(): bool
    {
        return match ($this) {
            self::Png, self::Gif, self::Bmp, self::Svg => false,
            default => true,
        };
    }

    /**
     * The quality to encode at when the caller did not name one.
     *
     * AVIF and JXL reach the same perceived quality at a lower number than JPEG
     * does, so one shared constant would either bloat the AVIF or starve the JPEG.
     */
    public function defaultQuality(): int
    {
        return match ($this) {
            self::Jpeg, self::Tiff => 82,
            self::Webp => 80,
            self::Avif, self::Heic => 55,
            self::Jxl => 75,
            self::Png, self::Gif, self::Bmp, self::Svg => 100,
        };
    }
}
