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

namespace Alto\Image\Driver\Gd;

use Alto\Image\Colour;
use Alto\Image\Driver\Capabilities;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Plan;
use Alto\Image\Driver\Support;
use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Exception\DriverException;
use Alto\Image\Format;
use Alto\Image\Internal\QualitySearch;
use Alto\Image\MetadataPolicy;
use Alto\Image\Operation\Adjust;
use Alto\Image\Operation\Blur;
use Alto\Image\Operation\Crop;
use Alto\Image\Operation\Escape;
use Alto\Image\Operation\Extend;
use Alto\Image\Operation\Flatten;
use Alto\Image\Operation\Flip;
use Alto\Image\Operation\Grayscale;
use Alto\Image\Operation\IccConvert;
use Alto\Image\Operation\Invert;
use Alto\Image\Operation\OperationInterface;
use Alto\Image\Operation\Orient;
use Alto\Image\Operation\Overlay;
use Alto\Image\Operation\Pixelate;
use Alto\Image\Operation\Resize;
use Alto\Image\Operation\Rotate;
use Alto\Image\Operation\Sharpen;
use Alto\Image\Operation\Tint;
use Alto\Image\Operation\Trim;
use Alto\Image\Result;
use Alto\Image\Size;

/**
 * Processes negotiated image plans with the GD extension.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GdDriver implements DriverInterface
{
    private ?Capabilities $capabilities = null;
    private readonly GdPipeline $pipeline;

    public function __construct()
    {
        $this->pipeline = new GdPipeline();
    }

    public static function isAvailable(): bool
    {
        return \extension_loaded('gd');
    }

    public function name(): string
    {
        return 'gd';
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities ??= $this->probe();
    }

    public function supports(OperationInterface $operation): Support
    {
        return match (true) {
            // Geometry is resolved above this driver, so it executes ten integers.
            $operation instanceof Resize,
            $operation instanceof Crop,
            $operation instanceof Extend,
            $operation instanceof Trim,
            $operation instanceof Flip,
            $operation instanceof Orient,
            $operation instanceof Flatten,
            $operation instanceof Overlay,
            $operation instanceof Grayscale,
            $operation instanceof Invert,
            $operation instanceof Pixelate,
            $operation instanceof Escape,

            // GD uses the same ceil(w|cos| + h|sin|) bounding box as Rotate.
            $operation instanceof Rotate => Support::Exact,

            // imagefilter(IMG_FILTER_GAUSSIAN_BLUR) takes no radius at all, so a
            // sigma is approximated by repeating a fixed 3x3 kernel. This is the
            // case that made Support three-valued: a boolean would force GD to
            // either claim an accuracy it does not have or refuse work it can
            // very nearly do.
            $operation instanceof Blur,
            $operation instanceof Sharpen,
            $operation instanceof Adjust,
            $operation instanceof Tint => Support::Approximate,

            // GD has no concept of a colour profile. Answering anything but No
            // here would let a CMYK photograph reach a browser as inverted mud
            // while the library reported success.
            $operation instanceof IccConvert => Support::No,

            default => Support::No,
        };
    }

    public function canDecode(Format $format): Support
    {
        if (!self::reads($format)) {
            return Support::No;
        }

        // GD decodes the first frame and discards the rest, which is a real
        // answer to "can you read this" but not an exact one.
        return Format::Gif === $format || Format::Webp === $format ? Support::Approximate : Support::Exact;
    }

    public function canEncode(Encoding $encoding): Support
    {
        $format = $encoding->format;

        if (null !== $format && !self::writes($format)) {
            return Support::No;
        }

        // GD writes no EXIF, no IPTC, no XMP and no ICC profile, so any policy
        // other than stripping is a promise it cannot keep.
        if (MetadataPolicy::Strip !== $encoding->metadata) {
            return Support::Approximate;
        }

        if (\Alto\Image\Effort::Balanced !== $encoding->effort && !\in_array($format, [Format::Png, Format::Avif], true)) {
            return Support::Approximate;
        }

        return Support::Exact;
    }

    public function process(Plan $plan): array
    {
        $started = microtime(true);
        $master = null;
        $results = [];

        // Give the last rendered output the decoded master. Earlier outputs need
        // private copies because operations mutate their raster.
        $rendering = array_values(array_filter(
            array_keys($plan->requests),
            fn(int $index): bool => !$plan->isPassThrough($index),
        ));
        $last = end($rendering);

        foreach (array_keys($plan->requests) as $index) {
            if ($plan->isPassThrough($index)) {
                $results[] = $this->passThrough($plan, $index, microtime(true) - $started);

                continue;
            }

            // Decode on the first output that needs pixels and reuse the result.
            $master ??= $this->decode($plan);

            $results[] = $this->render($plan, $index, $master, $started, $index === $last);
        }

        return $results;
    }

    private function render(Plan $plan, int $index, \GdImage $master, float $started, bool $isLast): Result
    {
        $spec = $plan->requests[$index];
        $expected = $plan->outputs[$index];

        // The pipeline preserves the shared master until an operation either
        // detaches into a new raster or genuinely needs a private copy.
        [$image, $degradations] = $this->pipeline->run($master, $plan->operations($index), !$isLast);
        $degradations = [...$plan->degradations, ...$degradations];

        $actual = new Size(imagesx($image), imagesy($image));

        // Enforce the projected size at runtime to prevent silent layout drift.
        if ($spec->transform->isMeasurable() && !$actual->equals($expected->size)) {
            // @codeCoverageIgnoreStart
            throw DriverException::failed('gd', 'checking its own work', \sprintf(
                'The plan projected %s and the pipeline produced %s for "%s". That is a bug in this driver.',
                $expected->size,
                $actual,
                $spec,
            ));
            // @codeCoverageIgnoreEnd
        }

        // The projection determines whether transparent pixels can reach the
        // encoder, avoiding an unnecessary alpha channel or flattening pass.
        $carriesAlpha = $spec->transform->estimate($plan->input)->hasAlpha;

        if (!$carriesAlpha) {
            imagesavealpha($image, false);
        }

        $encoding = $spec->encoding->resolve($plan->input->format);
        [$bytes, $encodeNotes] = $this->encode(
            $image,
            $encoding,
            $encoding->formatOr($plan->input->format),
            $carriesAlpha,
        );

        return new Result(
            $expected->with(size: $actual, bytes: \strlen($bytes), hasMetadata: false),
            $bytes,
            $this->name(),
            array_values(array_unique([...$degradations, ...$encodeNotes])),
            microtime(true) - $started,
        );
    }

    /**
     * Copies source bytes for an unchanged output.
     */
    private function passThrough(Plan $plan, int $index, float $duration): Result
    {
        $bytes = $plan->source->contents();

        return new Result(
            $plan->outputs[$index]->with(bytes: \strlen($bytes)),
            $bytes,
            $this->name(),
            $plan->degradations,
            $duration,
            null,
            true,
        );
    }

    private function decode(Plan $plan): \GdImage
    {
        [$image, $warnings] = $this->quietly(fn(): \GdImage|false => imagecreatefromstring($plan->source->contents()));

        if (false === $image) {
            throw CorruptImageException::unreadableHeader($plan->source->origin());
        }

        $this->judge($warnings, $plan);

        // A palette image cannot hold the intermediate colours a resample
        // produces, so everything is promoted before anything touches it.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $this->orient($image, $plan->source->metadata()->orientation);
    }

    /**
     * Runs a decode and collects warnings for the resulting exception.
     *
     * GD reports a truncated JPEG as a warning and hands back the rows it did
     * manage to reconstruct, which is a real answer for a thumbnail and a
     * disaster for a print job. Which of those you have is Limits::$failOn.
     *
     * @template T
     *
     * @param \Closure(): T $decode
     *
     * @return array{T, list<string>}
     */
    private function quietly(\Closure $decode): array
    {
        $warnings = [];

        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            return [$decode(), $warnings];
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function judge(array $warnings, Plan $plan): void
    {
        foreach ($warnings as $warning) {
            if ($plan->limits->failOn->rejects($warning)) {
                throw CorruptImageException::unreadableHeader(\sprintf(
                    '%s: the decoder said "%s", and Limits::$failOn is %s',
                    $plan->source->origin(),
                    trim($warning),
                    $plan->limits->failOn->value,
                ));
            }
        }
    }

    /**
     * Spends the EXIF orientation, which is why Orient the operation is a no-op
     * in the ordinary path: a Plan orients before it projects, so this puts the
     * pixels where the projection already assumed they were.
     */
    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (1 === $orientation) {
            return $image;
        }

        // EXIF names each value as a mirror followed by a clockwise rotation, and
        // GD rotates anticlockwise, so every angle here is the complement of the
        // one the tag is named after. 5 is "mirror and 270 clockwise" and 7 is
        // "mirror and 90 clockwise"; getting those two the same way round is the
        // whole reason fixtures/corpus carries all eight.
        $rotation = match ($orientation) {
            3, 4 => 180.0,
            6, 7 => 270.0,
            5, 8 => 90.0,
            default => 0.0,
        };

        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, \IMG_FLIP_HORIZONTAL);
        }

        if (0.0 === $rotation) {
            return $image;
        }

        $rotated = imagerotate($image, $rotation, 0);

        if (false === $rotated) {
            // @codeCoverageIgnoreStart
            throw DriverException::failed('gd', 'applying the EXIF orientation');
            // @codeCoverageIgnoreEnd
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    /**
     * The resolved format is explicit because Encoding::$format may retain the
     * source format.
     *
     * @return array{string, list<string>}
     */
    private function encode(\GdImage $image, Encoding $encoding, Format $format, bool $carriesAlpha): array
    {
        $notes = [];

        if (null === $encoding->maxBytes || !$format->isLossy()) {
            return [$this->write($image, $format, $encoding, $encoding->qualityFor($format), $carriesAlpha), $notes];
        }

        [$bytes, $quality, $met] = QualitySearch::under(
            $encoding->maxBytes,
            $encoding->qualityFor($format),
            fn(int $q): string => $this->write($image, $format, $encoding, $q, $carriesAlpha),
        );

        if (!$met) {
            $notes[] = \sprintf(
                'could not reach %d bytes: %s at quality %d is %d bytes, and going lower stops being the same picture',
                $encoding->maxBytes,
                $format->value,
                $quality,
                \strlen($bytes),
            );
        }

        return [$bytes, $notes];
    }

    private function write(\GdImage $image, Format $format, Encoding $encoding, int $quality, bool $carriesAlpha): string
    {
        // JPEG and BMP cannot encode alpha. Flatten only sources that may contain
        // transparent pixels and use white as the explicit background.
        if ($carriesAlpha && !$format->supportsAlpha()) {
            $image = $this->flattenForOpaqueFormat($image);
        }

        ob_start();

        try {
            $written = match ($format) {
                Format::Jpeg => imagejpeg($image, null, $quality),
                Format::Png => imagepng($image, null, $encoding->effort->compression()),
                Format::Webp => imagewebp($image, null, $encoding->lossless ? \IMG_WEBP_LOSSLESS : $quality),
                Format::Avif => imageavif($image, null, $encoding->lossless ? -1 : $quality, $encoding->effort->speed()),
                Format::Gif => imagegif($image),
                Format::Bmp => imagebmp($image, null, false),
                // @codeCoverageIgnoreStart
                default => throw DriverException::failed('gd', 'encoding', \sprintf(
                    'It reached %s, which GdDriver::canEncode() should have refused.',
                    $format->value,
                )),
                // @codeCoverageIgnoreEnd
            };

            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (!$written || false === $bytes || '' === $bytes) {
            // @codeCoverageIgnoreStart
            throw DriverException::failed('gd', 'encoding to ' . $format->value, 'The encoder produced nothing.');
            // @codeCoverageIgnoreEnd
        }

        return $bytes;
    }

    private function flattenForOpaqueFormat(\GdImage $image): \GdImage
    {
        $canvas = $this->pipeline->canvas(imagesx($image), imagesy($image), 0xFFFFFFFF);
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagealphablending($canvas, false);

        return $canvas;
    }

    /**
     * What gd_info() said, read once.
     *
     * Negotiation asks these two questions on every render, so neither of them
     * may go through capabilities(): building the whole table is what a doctor
     * run is for, not what a web request should pay for.
     *
     * @var array<string, bool>|null
     */
    private static ?array $info = null;

    private static function reads(Format $format): bool
    {
        return \in_array($format, self::formats()[0], true);
    }

    private static function writes(Format $format): bool
    {
        return \in_array($format, self::formats()[1], true);
    }

    /**
     * @return array{list<Format>, list<Format>} what this libgd reads and writes
     */
    private static function formats(): array
    {
        if (!self::isAvailable()) {
            // @codeCoverageIgnoreStart
            return [[], []];
            // @codeCoverageIgnoreEnd
        }

        if (null === self::$info) {
            $flags = [];

            foreach (gd_info() as $key => $value) {
                $flags[(string) $key] = true === $value;
            }

            self::$info = $flags;
        }

        $reads = [];
        $writes = [];

        foreach ([
            'JPEG Support' => Format::Jpeg,
            'PNG Support' => Format::Png,
            'GIF Read Support' => Format::Gif,
            'WebP Support' => Format::Webp,
            'AVIF Support' => Format::Avif,
            'BMP Support' => Format::Bmp,
        ] as $key => $format) {
            if (self::$info[$key] ?? false) {
                $reads[] = $format;

                if (Format::Gif !== $format || (self::$info['GIF Create Support'] ?? false)) {
                    $writes[] = $format;
                }
            }
        }

        return [$reads, $writes];
    }

    /**
     * Probes the formats supported by the installed libgd build.
     */
    private function probe(): Capabilities
    {
        if (!self::isAvailable()) {
            // @codeCoverageIgnoreStart
            return Capabilities::missing($this->name());
            // @codeCoverageIgnoreEnd
        }

        [$reads, $writes] = self::formats();

        return new Capabilities(
            $this->name(),
            'libgd ' . self::version(gd_info()),
            $reads,
            $writes,
            $this->operationSupport(),
            $this->notes(),
        );
    }

    /**
     * @param array<array-key, mixed> $info what gd_info() hands back
     */
    private static function version(array $info): string
    {
        $version = $info['GD Version'] ?? null;

        return \is_string($version) ? $version : 'unknown';
    }

    /**
     * @return array<class-string<OperationInterface>, Support>
     */
    private function operationSupport(): array
    {
        return [
            Resize::class => Support::Exact,
            Crop::class => Support::Exact,
            Extend::class => Support::Exact,
            Trim::class => Support::Exact,
            Flip::class => Support::Exact,
            Orient::class => Support::Exact,
            Flatten::class => Support::Exact,
            Overlay::class => Support::Exact,
            Grayscale::class => Support::Exact,
            Invert::class => Support::Exact,
            Pixelate::class => Support::Exact,
            Escape::class => Support::Exact,
            Rotate::class => Support::Exact,
            Blur::class => Support::Approximate,
            Sharpen::class => Support::Approximate,
            Adjust::class => Support::Approximate,
            Tint::class => Support::Approximate,
            IccConvert::class => Support::No,
        ];
    }

    /**
     * @return list<string>
     */
    private function notes(): array
    {
        $notes = ['no HEIC, TIFF, SVG or animated WebP support.'];

        if (self::isLinkedExternally()) {
            $notes[] = 'linked EXTERNALLY: pixel buffers use plain malloc and escape memory_limit entirely. '
                . 'Limits::maxPixels is your only decompression-bomb guard.';
        }

        return $notes;
    }

    /**
     * Whether this build uses the system libgd rather than the one bundled with PHP.
     *
     * It matters because the bundled build allocates through PHP and the external
     * one does not, so on the external build a 48 MB pixel buffer reports as
     * 2 MB and a 64 MB memory_limit does not stop it.
     */
    public static function isLinkedExternally(): bool
    {
        if (!self::isAvailable()) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        // The bundled libgd reports a version string of the form "bundled (2.1.0 compatible)".
        return !str_contains(self::version(gd_info()), 'bundled');
    }
}
