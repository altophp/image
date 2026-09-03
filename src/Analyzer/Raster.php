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

namespace Alto\Image\Analyzer;

use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\MetadataPolicy;
use Alto\Image\Scaling;

/**
 * A bounded, row-major RGBA pixel buffer for image analysis.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Raster
{
    public const int MAX = 64;

    /**
     * @param list<int> $pixels packed 0xAARRGGBB, row-major, top-left first
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $pixels,
    ) {
        if ($width < 1 || $height < 1 || $width > self::MAX || $height > self::MAX) {
            throw new InvalidArgumentException(\sprintf(
                'A raster is between 1x1 and %dx%d, got %dx%d. The cap is what keeps analyzers naive and free.',
                self::MAX,
                self::MAX,
                $width,
                $height,
            ));
        }

        if (\count($pixels) !== $width * $height) {
            throw new InvalidArgumentException(\sprintf(
                'A %dx%d raster holds %d pixels, got %d.',
                $width,
                $height,
                $width * $height,
                \count($pixels),
            ));
        }
    }

    /**
     * Runs the image's own transform down to a thumbnail and decodes it.
     *
     * The intermediate is an uncompressed BMP, which is the one raw target every
     * driver already writes and which parses in twenty lines of pure PHP. That is
     * what lets DriverInterface stay at six methods: getting pixels back out of a
     * driver needed no seventh.
     *
     * One thing that costs: GD writes 24-bit BMPs, so alpha does not survive the
     * round trip and every pixel arrives opaque. None of the analyzers here need
     * it. An analyzer that needs alpha should flatten() onto a known background
     * before decoding.
     */
    public static function of(Image $image, int $size = self::MAX): self
    {
        $size = max(1, min(self::MAX, $size));

        return self::fromBmp(
            $image
                ->fit($size, $size, Scaling::Down)
                ->encode(Format::Bmp, metadata: MetadataPolicy::Strip)
                ->bytes(),
        );
    }

    /**
     * Reads an uncompressed 24 or 32 bit BMP.
     */
    public static function fromBmp(string $bytes): self
    {
        if (!str_starts_with($bytes, 'BM') || \strlen($bytes) < 54) {
            throw new InvalidArgumentException('A raster reads an uncompressed BMP, and these bytes are not one.');
        }

        /** @var array{offset: int, width: int, height: int, bpp: int, compression: int}|false $header */
        // From byte 2: file size, two reserved shorts, the pixel offset, the DIB
        // header size, then the geometry.
        $header = unpack('x8/Voffset/x4/lwidth/lheight/x2/vbpp/Vcompression', substr($bytes, 2, 32));

        if (false === $header) {
            // @codeCoverageIgnoreStart
            throw new InvalidArgumentException('This BMP has no readable info header.');
            // @codeCoverageIgnoreEnd
        }

        // 0 is BI_RGB and 3 is BI_BITFIELDS, which is still uncompressed.
        if (!\in_array($header['compression'], [0, 3], true)) {
            throw new InvalidArgumentException('A raster reads an uncompressed BMP, and this one is compressed.');
        }

        if (!\in_array($header['bpp'], [24, 32], true)) {
            throw new InvalidArgumentException(\sprintf('A raster reads 24 or 32 bit BMPs, got %d.', $header['bpp']));
        }

        $width = abs($header['width']);
        // A negative height means the rows are already top-down.
        $topDown = $header['height'] < 0;
        $height = abs($header['height']);
        $step = intdiv($header['bpp'], 8);
        $stride = intdiv($header['bpp'] * $width + 31, 32) * 4;
        $pixels = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = $header['offset'] + ($topDown ? $y : $height - 1 - $y) * $stride;

            for ($x = 0; $x < $width; ++$x) {
                $at = $row + $x * $step;
                $blue = \ord($bytes[$at] ?? "\x00");
                $green = \ord($bytes[$at + 1] ?? "\x00");
                $red = \ord($bytes[$at + 2] ?? "\x00");
                $alpha = 32 === $header['bpp'] ? \ord($bytes[$at + 3] ?? "\xFF") : 255;

                // A 32-bit BMP whose alpha channel is entirely zero is opaque
                // with an unused fourth byte, not a fully transparent image.
                $pixels[] = ($alpha << 24) | ($red << 16) | ($green << 8) | $blue;
            }
        }

        return self::opaqueIfUnused(new self($width, $height, $pixels));
    }

    public function at(int $x, int $y): int
    {
        if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
            throw new InvalidArgumentException(\sprintf(
                'Pixel (%d, %d) falls outside a %dx%d raster.',
                $x,
                $y,
                $this->width,
                $this->height,
            ));
        }

        return $this->pixels[$y * $this->width + $x];
    }

    /**
     * @return array{int, int, int, int} red, green, blue, alpha
     */
    public function rgba(int $x, int $y): array
    {
        $packed = $this->at($x, $y);

        return [($packed >> 16) & 0xFF, ($packed >> 8) & 0xFF, $packed & 0xFF, ($packed >> 24) & 0xFF];
    }

    /**
     * Rec. 709 luma, which is what every perceptual measure in this package uses.
     */
    public function luma(int $x, int $y): float
    {
        [$red, $green, $blue] = $this->rgba($x, $y);

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    public function count(): int
    {
        return $this->width * $this->height;
    }

    /**
     * A square copy by box average, for the analyzers that need one.
     *
     * Box averaging preserves symmetry. Nearest-neighbour sampling from one corner
     * can change a perceptual hash when the source is mirrored.
     */
    public function resampledTo(int $size): self
    {
        $size = max(1, min(self::MAX, $size));

        if ($size === $this->width && $size === $this->height) {
            return $this;
        }

        $pixels = [];

        for ($y = 0; $y < $size; ++$y) {
            $top = (int) floor($y * $this->height / $size);
            $bottom = max($top + 1, (int) floor(($y + 1) * $this->height / $size));

            for ($x = 0; $x < $size; ++$x) {
                $left = (int) floor($x * $this->width / $size);
                $right = max($left + 1, (int) floor(($x + 1) * $this->width / $size));

                $pixels[] = $this->average($left, $top, min($right, $this->width), min($bottom, $this->height));
            }
        }

        return new self($size, $size, $pixels);
    }

    /**
     * The mean of one box, weighted by nothing, which is what a box filter is.
     */
    private function average(int $left, int $top, int $right, int $bottom): int
    {
        $sums = [0, 0, 0, 0];
        $count = 0;

        for ($y = $top; $y < $bottom; ++$y) {
            for ($x = $left; $x < $right; ++$x) {
                [$red, $green, $blue, $alpha] = $this->rgba($x, $y);
                $sums[0] += $red;
                $sums[1] += $green;
                $sums[2] += $blue;
                $sums[3] += $alpha;
                ++$count;
            }
        }

        return (intdiv($sums[3], $count) << 24)
            | (intdiv($sums[0], $count) << 16)
            | (intdiv($sums[1], $count) << 8)
            | intdiv($sums[2], $count);
    }

    private static function opaqueIfUnused(self $raster): self
    {
        foreach ($raster->pixels as $pixel) {
            if (0 !== ($pixel >> 24 & 0xFF)) {
                return $raster;
            }
        }

        return new self(
            $raster->width,
            $raster->height,
            array_map(static fn(int $p): int => $p | (0xFF << 24), $raster->pixels),
        );
    }
}
