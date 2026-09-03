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

namespace Alto\Image\Driver\Imagick;

use Alto\Image\Driver\Capabilities;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Plan;
use Alto\Image\Driver\Support;
use Alto\Image\Effort;
use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Exception\DriverException;
use Alto\Image\Format;
use Alto\Image\Internal\QualitySearch;
use Alto\Image\Limits;
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
use Alto\Image\Operation\Placement;
use Alto\Image\Operation\Resize;
use Alto\Image\Operation\Rotate;
use Alto\Image\Operation\Sharpen;
use Alto\Image\Operation\Solvable;
use Alto\Image\Operation\Tint;
use Alto\Image\Operation\Trim;
use Alto\Image\Result;
use Alto\Image\Size;

/**
 * Processes negotiated image plans with the Imagick extension.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ImagickDriver implements DriverInterface
{
    private ?Capabilities $capabilities = null;
    private readonly ImagickPipeline $pipeline;

    public function __construct()
    {
        $this->pipeline = new ImagickPipeline();
    }

    public static function isAvailable(): bool
    {
        return \extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    public function name(): string
    {
        return 'imagick';
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities ??= $this->probe();
    }

    public function supports(OperationInterface $operation): Support
    {
        return match (true) {
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
            $operation instanceof Tint,
            $operation instanceof Escape,

            // The parameters these carry are the parameters ImageMagick takes,
            // which is exactly what GD cannot say about the same three.
            $operation instanceof Blur,
            $operation instanceof Sharpen,
            $operation instanceof Adjust => Support::Exact,

            $operation instanceof IccConvert => $this->hasLcms() ? Support::Exact : Support::No,

            // ImageMagick's rotation bounding box is its own, and this driver
            // conforms it to the projection. Exact for quarter turns, a pixel or
            // two of edge for anything else.
            $operation instanceof Rotate => $operation->isQuarterTurn() ? Support::Exact : Support::Approximate,

            default => Support::No,
        };
    }

    /**
     * Avoids querying the full coder registry during normal negotiation.
     *
     * Unsupported builds fail during decode. capabilities() performs the full
     * probe for diagnostics.
     */
    public function canDecode(Format $format): Support
    {
        if (!self::isAvailable()) {
            // @codeCoverageIgnoreStart
            return Support::No;
            // @codeCoverageIgnoreEnd
        }

        // Animation survives as its first frame, which is an answer but not an
        // exact one. Vectors are rasterised at their declared size only.
        return $format->supportsAnimation() || $format->isVector() ? Support::Approximate : Support::Exact;
    }

    public function canEncode(Encoding $encoding): Support
    {
        $format = $encoding->format;

        if (null !== $format && ($format->isVector() || !$this->writesFormat($format))) {
            return Support::No;
        }

        // ImageMagick does not provide a reliable AVIF or HEIC effort option.
        // Report non-default effort as approximate instead of ignoring it.
        if (Effort::Balanced !== $encoding->effort && !\in_array($format, [Format::Png, Format::Webp], true)) {
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
        // copy-on-write clones because their operations mutate the image.
        $rendering = array_values(array_filter(
            array_keys($plan->requests),
            fn(int $index): bool => !$plan->isPassThrough($index),
        ));
        $last = end($rendering);

        try {
            foreach (array_keys($plan->requests) as $index) {
                if ($plan->isPassThrough($index)) {
                    $results[] = $this->passThrough($plan, $index, microtime(true) - $started);

                    continue;
                }

                $master ??= $this->decode($plan);
                $results[] = $this->render($plan, $index, $master, $started, $index === $last);
            }
        } finally {
            $master?->clear();
        }

        return $results;
    }

    private function render(Plan $plan, int $index, \Imagick $master, float $started, bool $isLast): Result
    {
        $spec = $plan->requests[$index];
        $expected = $plan->outputs[$index];

        // Every output but the last works on its own copy, because the pipeline
        // mutates and the master has to survive for the next one.
        $image = $isLast ? $master : clone $master;

        try {
            [$image, $notes] = $this->pipeline->run($image, $plan->operations($index));
            $degradations = [...$plan->degradations, ...$notes];
            $actual = $this->pipeline->size($image);

            if ($spec->transform->isMeasurable() && !$actual->equals($expected->size)) {
                // @codeCoverageIgnoreStart
                throw DriverException::failed('imagick', 'checking its own work', \sprintf(
                    'The plan projected %s and the pipeline produced %s for "%s". That is a bug in this driver.',
                    $expected->size,
                    $actual,
                    $spec,
                ));
                // @codeCoverageIgnoreEnd
            }

            // Remove alpha only when the projection guarantees that no transparent
            // pixels can reach the encoder.
            $carriesAlpha = $spec->transform->estimate($plan->input)->hasAlpha;

            if (!$carriesAlpha) {
                $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            }

            $encoding = $spec->encoding->resolve($plan->input->format);
            $format = $encoding->formatOr($plan->input->format);
            [$bytes, $encodeNotes] = $this->encode($image, $encoding, $format, $carriesAlpha);

            if (Support::Approximate === $this->canEncode($encoding)) {
                $encodeNotes[] = \sprintf(
                    'imagick wrote %s but could not honour every encoding option',
                    $format->value,
                );
            }

            return new Result(
                $expected->with(
                    size: $actual,
                    bytes: \strlen($bytes),
                    hasMetadata: $encoding->metadata->keepsMetadata() && $plan->input->hasMetadata,
                ),
                $bytes,
                $this->name(),
                array_values(array_unique([...$degradations, ...$encodeNotes])),
                microtime(true) - $started,
            );
        } finally {
            // The last output was handed the master itself, and process() clears
            // that one; clearing it here as well would be a double free.
            if (!$isLast) {
                $image->clear();
            }
        }
    }

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

    private function decode(Plan $plan): \Imagick
    {
        ResourcePolicy::apply($plan->limits);

        $placements = $this->firstPlacements($plan);
        $hint = ShrinkOnLoad::hint($plan->input, $placements);
        $image = $this->read($plan, $hint);

        // Trust nothing about a decoder's idea of "about this size". If the rung
        // it picked is smaller than the geometry needs, the resize would clamp
        // and produce a box the header already promised would be bigger, so the
        // shrink is thrown away and the file is read again in full.
        if (null !== $hint && $this->tooSmall($image, $placements)) {
            // @codeCoverageIgnoreStart
            $image->clear();
            $image = $this->read($plan, null);
            // @codeCoverageIgnoreEnd
        }

        // An animation arrives as N frames; this driver ships the first one and
        // canDecode() has already said so.
        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
            $first = $image->getImage();
            $image->clear();
            $image = $first;
        }

        // The Plan already oriented the metadata, so this puts the pixels where
        // the projection assumed they were, then spends the tag.
        $this->orient($image, $plan->source->metadata()->orientation);

        $image->setImagePage(0, 0, 0, 0);

        // jpeg:size decodes to at least the hint, never exactly it, so whatever
        // came back is now the truth the pipeline resizes from.
        return $image;
    }

    /**
     * Spends the EXIF orientation, in eight explicit steps.
     *
     * Not autoOrientImage(), which several shipped builds of the extension do
     * not have, including the one this was written against. Eight lines beat a
     * feature test and a fallback, and they read the same way the GD driver's
     * eight do, which is how a reviewer can check that the two agree.
     */
    private function orient(\Imagick $image, int $orientation): void
    {
        if (1 === $orientation) {
            return;
        }

        // rotateImage() measures clockwise, which is the same direction EXIF
        // names its values in, so unlike the GD driver these angles are literal.
        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            $image->flopImage();
        }

        $degrees = match ($orientation) {
            3, 4 => 180.0,
            5, 8 => 270.0,
            6, 7 => 90.0,
            default => 0.0,
        };

        if (0.0 !== $degrees) {
            $image->rotateImage($this->pipeline->pixel(0xFF000000), $degrees);
        }

        if (\defined(\Imagick::class . '::ORIENTATION_TOPLEFT')) {
            $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        }
    }

    /**
     * @throws CorruptImageException
     */
    private function read(Plan $plan, ?string $hint): \Imagick
    {
        $image = new \Imagick();

        if (null !== $hint) {
            // Before readImage(), which is the entire point. After it, libjpeg
            // has already reconstructed every DCT block and the option is inert.
            $image->setOption('jpeg:size', $hint);
        }

        try {
            $image->readImageBlob($plan->source->contents(), $plan->source->origin());
        } catch (\ImagickException $error) {
            $image->clear();

            // canDecode() answered optimistically to keep queryFormats() off the
            // hot path, so this is where a build that really cannot read the
            // format has to say so, and say where the true list is.
            if (!$this->writesFormat($plan->input->format) && !$this->knowsFormat($plan->input->format)) {
                throw new DriverException(\sprintf(
                    "This ImageMagick has no coder for %s.\n"
                    . "  Run vendor/bin/image doctor for the available formats.\n"
                    . '  It said: %s',
                    $plan->input->format->value,
                    $error->getMessage(),
                ), 0, $error);
            }

            throw CorruptImageException::unreadableHeader($plan->source->origin() . ': ' . $error->getMessage());
        }

        return $image;
    }

    /**
     * @param list<Placement> $placements
     */
    private function tooSmall(\Imagick $image, array $placements): bool
    {
        $size = $this->pipeline->size($image);

        foreach ($placements as $placement) {
            if ($placement->scaleWidth > $size->width || $placement->scaleHeight > $size->height) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first geometry step of every output, which is what shrink-on-load needs
     * to know how small it is allowed to decode.
     *
     * @return list<Placement>
     */
    private function firstPlacements(Plan $plan): array
    {
        $placements = [];

        foreach (array_keys($plan->requests) as $index) {
            foreach ($plan->requests[$index]->transform->operations as $operation) {
                if ($operation instanceof Solvable) {
                    $placements[] = $operation->solve($plan->input->size);

                    continue 2;
                }
            }

            // An output with no geometry at all needs every pixel.
            return [];
        }

        return $placements;
    }

    /**
     * The resolved format is explicit because Encoding::$format may retain the
     * source format.
     *
     * @return array{string, list<string>}
     */
    private function encode(\Imagick $image, Encoding $encoding, Format $format, bool $carriesAlpha): array
    {
        $notes = [];

        $this->applyMetadataPolicy($image, $encoding->metadata);
        $image->setImageFormat($this->magickName($format));
        $this->applyFormatOptions($image, $format, $encoding);

        if (null === $encoding->maxBytes || !$format->isLossy()) {
            return [$this->write($image, $format, $encoding->qualityFor($format), $carriesAlpha), $notes];
        }

        [$bytes, $quality, $met] = QualitySearch::under(
            $encoding->maxBytes,
            $encoding->qualityFor($format),
            fn(int $q): string => $this->write($image, $format, $q, $carriesAlpha),
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

    private function write(\Imagick $image, Format $format, int $quality, bool $carriesAlpha): string
    {
        if ($format->isLossy()) {
            $image->setImageCompressionQuality($quality);
        }

        // Only when there is something to flatten. An opaque source going to an
        // opaque format has nothing to composite, and saying so is free.
        if ($carriesAlpha && !$format->supportsAlpha()) {
            $image->setImageBackgroundColor(new \ImagickPixel('white'));
            $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        }

        try {
            $bytes = $image->getImagesBlob();
            // @codeCoverageIgnoreStart
        } catch (\ImagickException $error) {
            throw DriverException::failed('imagick', 'encoding to ' . $format->value, $error->getMessage());
            // @codeCoverageIgnoreEnd
        }

        if ('' === $bytes) {
            // @codeCoverageIgnoreStart
            throw DriverException::failed('imagick', 'encoding to ' . $format->value, 'The encoder produced nothing.');
            // @codeCoverageIgnoreEnd
        }

        return $bytes;
    }

    private function applyFormatOptions(\Imagick $image, Format $format, Encoding $encoding): void
    {
        match ($format) {
            Format::Jpeg => $this->jpegOptions($image, $encoding),
            Format::Png => $image->setOption('png:compression-level', (string) $encoding->effort->compression()),
            Format::Webp => $this->webpOptions($image, $encoding),
            // No reliable speed option is available for AVIF or HEIC. Effort is
            // reported as approximate by canEncode().
            Format::Avif, Format::Heic => null,
            Format::Bmp => $image->setImageCompression(\Imagick::COMPRESSION_NO),
            default => null,
        };
    }

    private function jpegOptions(\Imagick $image, Encoding $encoding): void
    {
        $image->setInterlaceScheme($encoding->progressive ? \Imagick::INTERLACE_PLANE : \Imagick::INTERLACE_NO);
        $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $image->setSamplingFactors($encoding->qualityFor(Format::Jpeg) >= 90 ? ['1x1', '1x1', '1x1'] : ['2x2', '1x1', '1x1']);
    }

    private function webpOptions(\Imagick $image, Encoding $encoding): void
    {
        $image->setOption('webp:lossless', $encoding->lossless ? 'true' : 'false');
        $image->setOption('webp:method', (string) (Effort::Best === $encoding->effort ? 6 : 4));
    }

    private function applyMetadataPolicy(\Imagick $image, MetadataPolicy $policy): void
    {
        if (MetadataPolicy::Keep === $policy) {
            return;
        }

        // stripImage() removes the colour profile along with everything else, so
        // a policy that keeps the profile has to put it back.
        $profile = $policy->keepsProfile() ? $this->readProfile($image) : null;
        $copyright = MetadataPolicy::Copyright === $policy ? $this->readCopyright($image) : [];
        $image->stripImage();

        if (null !== $profile) {
            $image->profileImage('icc', $profile);
        }

        foreach ($copyright as $name => $value) {
            $image->setImageProperty($name, $value);
        }
    }

    /**
     * @return array<string, string>
     */
    private function readCopyright(\Imagick $image): array
    {
        try {
            $properties = $image->getImageProperties('*', true);
            // @codeCoverageIgnoreStart
        } catch (\ImagickException) {
            return [];
            // @codeCoverageIgnoreEnd
        }

        $copyright = [];

        foreach ($properties as $name => $value) {
            if (\is_string($name) && \is_string($value) && 1 === preg_match('/(?:copyright|artist|author|creator|by-line)$/i', $name)) {
                $copyright[$name] = $value;
            }
        }

        return $copyright;
    }

    private function readProfile(\Imagick $image): ?string
    {
        try {
            $profiles = $image->getImageProfiles('icc', true);
            // @codeCoverageIgnoreStart
        } catch (\ImagickException) {
            return null;
            // @codeCoverageIgnoreEnd
        }

        $profile = $profiles['icc'] ?? null;

        return \is_string($profile) && '' !== $profile ? $profile : null;
    }

    /**
     * Probes the formats supported by the installed ImageMagick build.
     */
    private function probe(): Capabilities
    {
        if (!self::isAvailable()) {
            // @codeCoverageIgnoreStart
            return Capabilities::missing($this->name());
            // @codeCoverageIgnoreEnd
        }

        $reads = [];
        $writes = [];

        foreach (Format::cases() as $format) {
            if (!$this->knowsFormat($format)) {
                continue;
            }

            $reads[] = $format;

            // SVG is read-only here on purpose: ImageMagick will write one, and
            // what it writes is a raster wrapped in an <image> tag, which is not
            // what anyone asking for an SVG means.
            if ($this->writesFormat($format)) {
                $writes[] = $format;
            }
        }

        $version = \Imagick::getVersion();
        $notes = ResourcePolicy::overridden(ResourcePolicy::apply(new Limits()));

        if (!$this->hasLcms()) {
            // @codeCoverageIgnoreStart
            $notes[] = 'built without lcms: ICC conversion is unavailable.';
            // @codeCoverageIgnoreEnd
        }

        return new Capabilities(
            $this->name(),
            \is_string($version['versionString'] ?? null) ? $version['versionString'] : 'imagick',
            $reads,
            $writes,
            $this->operationSupport(),
            $notes,
        );
    }

    /**
     * Whether this build can write a format, established by writing one pixel.
     *
     * queryFormats() does not distinguish readable from writable coders. Cache a
     * one-pixel encode so the capability table does not overstate write support.
     *
     * @var array<string, bool>
     */
    private static array $writable = [];

    /**
     * The coder list, which is the same for the life of the process.
     *
     * @var list<string>|null
     */
    private static ?array $known = null;

    private function knowsFormat(Format $format): bool
    {
        self::$known ??= array_values(array_map(strtoupper(...), \Imagick::queryFormats()));

        return \in_array($this->magickName($format), self::$known, true);
    }

    /**
     * Probes one format with a one-pixel encode and caches the result.
     */
    private function writesFormat(Format $format): bool
    {
        if ($format->isVector() || !self::isAvailable()) {
            return false;
        }

        $magick = $this->magickName($format);

        if (isset(self::$writable[$magick])) {
            return self::$writable[$magick];
        }

        try {
            $probe = new \Imagick();
            $probe->newImage(1, 1, new \ImagickPixel('white'));
            $probe->setImageFormat($magick);
            $written = '' !== $probe->getImagesBlob();
            $probe->clear();
        } catch (\Throwable) {
            $written = false;
        }

        return self::$writable[$magick] = $written;
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
            Tint::class => Support::Exact,
            Escape::class => Support::Exact,
            Blur::class => Support::Exact,
            Sharpen::class => Support::Exact,
            Adjust::class => Support::Exact,
            IccConvert::class => $this->hasLcms() ? Support::Exact : Support::No,
            Rotate::class => Support::Approximate,
        ];
    }

    private function hasLcms(): bool
    {
        if (!self::isAvailable()) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        $features = \Imagick::getVersion()['versionString'] ?? '';

        return \is_string($features) && (str_contains($features, 'lcms') || $this->delegatesSayLcms());
    }

    private function delegatesSayLcms(): bool
    {
        // When the version string omits delegates, probe with a one-pixel colour
        // space conversion.
        try {
            $probe = new \Imagick();
            $probe->newImage(1, 1, new \ImagickPixel('white'));
            $probe->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $probe->clear();

            return true;
            // @codeCoverageIgnoreStart
        } catch (\ImagickException) {
            return false;
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * What ImageMagick calls a format, which is not always what anyone else does.
     */
    private function magickName(Format $format): string
    {
        return match ($format) {
            Format::Jpeg => 'JPEG',
            Format::Tiff => 'TIFF',
            Format::Jxl => 'JXL',
            Format::Heic => 'HEIC',
            default => strtoupper($format->value),
        };
    }
}
