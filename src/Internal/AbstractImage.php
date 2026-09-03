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

namespace Alto\Image\Internal;

use Alto\Image\Anchor;
use Alto\Image\Colour;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Output;
use Alto\Image\Driver\Plan;
use Alto\Image\Effort;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Fit;
use Alto\Image\FocalPoint;
use Alto\Image\Focus;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\ImageSet;
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
use Alto\Image\Operation\Resize;
use Alto\Image\Operation\Rotate;
use Alto\Image\Operation\Sharpen;
use Alto\Image\Operation\Tint;
use Alto\Image\Operation\Trim;
use Alto\Image\Scaling;
use Alto\Image\Source;
use Alto\Image\Transform;

/**
 * Shared immutable fluent operations for Image and ImageSet.
 *
 * @internal Image and ImageSet are the public cardinality types.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract readonly class AbstractImage
{
    /**
     * @param non-empty-list<Output> $specs
     */
    protected function __construct(
        protected Source $source,
        protected array $specs,
        protected ?Limits $limits = null,
        protected ?DriverInterface $driver = null,
    ) {}

    /**
     * Uses this driver instead of automatic detection.
     */
    public function using(DriverInterface $driver): static
    {
        return $this->recreate($this->specs, $this->limits, $driver);
    }

    /**
     * Applies resource and input limits before a decoder is reached.
     */
    public function within(Limits $limits): static
    {
        return $this->recreate($this->specs, $limits, $this->driver);
    }

    /**
     * Fills the box exactly and crops its overflow.
     */
    public function cover(?int $width = null, ?int $height = null, ?float $ratio = null, Anchor|Focus|FocalPoint|null $gravity = null, ?Scaling $scaling = null): static
    {
        return $this->resize($width, $height, Fit::Cover, $ratio, $gravity, $scaling);
    }

    /**
     * Fills the box exactly and pads its shortfall.
     */
    public function contain(?int $width = null, ?int $height = null, string|int $background = 0x00000000, ?float $ratio = null, ?Anchor $gravity = null, ?Scaling $scaling = null): static
    {
        return $this->reshape(static fn(?Resize $resize): Resize => ($resize ?? new Resize($width, $height, Fit::Contain))->with(
            width: $width,
            height: $height,
            fit: Fit::Contain,
            gravity: $gravity,
            scaling: $scaling,
            ratio: $ratio,
            background: \is_string($background) ? Colour::parse($background) : $background,
        ));
    }

    /**
     * Fits inside the box without cropping or distortion.
     */
    public function fit(?int $width = null, ?int $height = null, ?Scaling $scaling = null): static
    {
        return $this->resize($width, $height, Fit::Inside, null, null, $scaling);
    }

    /**
     * Scales proportionally from one named axis.
     */
    public function scale(?int $width = null, ?int $height = null, ?Scaling $scaling = null): static
    {
        if (null !== $width && null !== $height) {
            throw new InvalidArgumentException('scale() takes one axis and keeps the ratio. Use fit() or stretch() for two.');
        }

        return $this->resize($width, $height, Fit::Inside, null, null, $scaling);
    }

    /**
     * Fills the box by scaling both axes independently.
     */
    public function stretch(int $width, int $height, ?Scaling $scaling = null): static
    {
        return $this->resize($width, $height, Fit::Fill, null, null, $scaling);
    }

    /**
     * The shaping primitive used by the named resize verbs.
     */
    public function resize(?int $width = null, ?int $height = null, ?Fit $fit = null, ?float $ratio = null, Anchor|Focus|FocalPoint|null $gravity = null, ?Scaling $scaling = null): static
    {
        return $this->reshape(static fn(?Resize $resize): Resize => ($resize ?? new Resize($width, $height, $fit ?? Fit::Inside, ratio: $ratio))->with(
            width: $width,
            height: $height,
            fit: $fit,
            gravity: $gravity,
            scaling: $scaling,
            ratio: $ratio,
        ));
    }

    public function crop(int $width, int $height, Anchor|Focus|FocalPoint|null $gravity = null, ?int $x = null, ?int $y = null): static
    {
        return $this->apply(new Crop($width, $height, $gravity ?? Anchor::Center, $x, $y));
    }

    public function extend(int $top = 0, ?int $right = null, ?int $bottom = null, ?int $left = null, string|int $background = 0x00000000): static
    {
        $colour = \is_string($background) ? Colour::parse($background) : $background;

        return $this->apply(null === $right && null === $bottom && null === $left
            ? Extend::all($top, $colour)
            : new Extend($top, $right ?? 0, $bottom ?? 0, $left ?? 0, $colour));
    }

    public function trim(int $threshold = 10, string|int|null $background = null): static
    {
        return $this->apply(new Trim($threshold, \is_string($background) ? Colour::parse($background) : $background));
    }

    public function rotate(float $degrees, string|int $background = 0x00000000): static
    {
        return $this->apply(new Rotate($degrees, \is_string($background) ? Colour::parse($background) : $background));
    }

    public function flipHorizontal(): static
    {
        return $this->apply(Flip::horizontal());
    }

    public function flipVertical(): static
    {
        return $this->apply(Flip::vertical());
    }

    public function orient(): static
    {
        return $this->apply(new Orient());
    }

    public function flatten(string|int $background = 0xFFFFFFFF): static
    {
        return $this->apply(new Flatten(\is_string($background) ? Colour::parse($background) : $background));
    }

    public function overlay(string $path, ?Anchor $gravity = null, float $opacity = 1.0, int $margin = 0): static
    {
        return $this->apply(new Overlay($path, $gravity ?? Anchor::BottomRight, $opacity, $margin));
    }

    public function blur(float $sigma = 1.0): static
    {
        return $this->apply(new Blur($sigma));
    }

    public function sharpen(float $sigma = 1.0, float $amount = 1.0): static
    {
        return $this->apply(new Sharpen($sigma, $amount));
    }

    public function adjust(int $brightness = 0, int $contrast = 0, int $saturation = 0, float $gamma = 1.0): static
    {
        return $this->apply(new Adjust($brightness, $contrast, $saturation, $gamma));
    }

    public function grayscale(): static
    {
        return $this->apply(new Grayscale());
    }

    public function invert(): static
    {
        return $this->apply(new Invert());
    }

    public function pixelate(int $size = 8): static
    {
        return $this->apply(new Pixelate($size));
    }

    public function tint(string|int $colour, float $strength = 1.0): static
    {
        return $this->apply(new Tint(\is_string($colour) ? Colour::parse($colour) : $colour, $strength));
    }

    /**
     * Converts pixels to a named ICC colour profile.
     */
    public function convertColourProfile(string $profile = 'srgb'): static
    {
        return $this->apply(new IccConvert($profile));
    }

    /**
     * @param \Closure(mixed): mixed $handler
     */
    public function escape(\Closure $handler, string $label = 'escape', ?string $identity = null): static
    {
        return $this->apply(new Escape($handler, $label, $identity));
    }

    /**
     * Appends first-party or custom operations in order.
     */
    public function apply(OperationInterface ...$operations): static
    {
        return $this->mapSpecs(static fn(Output $spec): Output => $spec->with(transform: $spec->transform->with(...$operations)));
    }

    /**
     * Replaces the complete transform, including one parsed from a string.
     */
    public function transformedBy(Transform $transform): static
    {
        return $this->mapSpecs(static fn(Output $spec): Output => $spec->with(transform: $transform));
    }

    public function jpeg(?int $quality = null, ?bool $progressive = null): static
    {
        return $this->encode(Format::Jpeg, $quality, progressive: $progressive);
    }

    public function png(?Effort $effort = null): static
    {
        return $this->encode(Format::Png, null, $effort);
    }

    public function webp(?int $quality = null, ?Effort $effort = null, ?bool $lossless = null): static
    {
        return $this->encode(Format::Webp, $quality, $effort, lossless: $lossless);
    }

    public function avif(?int $quality = null, ?Effort $effort = null, ?bool $lossless = null): static
    {
        return $this->encode(Format::Avif, $quality, $effort, lossless: $lossless);
    }

    /**
     * Configures the output codec directly when no named format method fits.
     */
    public function encode(?Format $format = null, ?int $quality = null, ?Effort $effort = null, ?MetadataPolicy $metadata = null, ?int $maxBytes = null, ?bool $progressive = null, ?bool $lossless = null): static
    {
        return $this->mapSpecs(static fn(Output $spec): Output => $spec->with(encoding: $spec->encoding->with(
            format: $format,
            quality: $quality,
            effort: $effort,
            metadata: $metadata,
            maxBytes: $maxBytes,
            progressive: $progressive,
            lossless: $lossless,
        )));
    }

    /**
     * Preserves metadata instead of applying the default stripping policy.
     */
    public function keepMetadata(): static
    {
        return $this->withMetadata(MetadataPolicy::Keep);
    }

    /**
     * Preserves the colour profile while removing private metadata.
     */
    public function keepColourProfile(): static
    {
        return $this->withMetadata(MetadataPolicy::ColourProfile);
    }

    /**
     * Configures exactly what metadata survives into the output.
     */
    public function withMetadata(MetadataPolicy $policy): static
    {
        return $this->encode(metadata: $policy);
    }

    /**
     * Returns one image per width, preserving every other setting.
     */
    public function widths(int ...$widths): ImageSet
    {
        return $this->multiply(array_values($widths), static fn(Output $spec, int $width): Output => $spec->with(
            transform: $spec->transform->withResize(static fn(?Resize $resize): Resize => ($resize ?? new Resize($width))->with(width: $width)),
        ));
    }

    /**
     * Returns one image per height, preserving every other setting.
     */
    public function heights(int ...$heights): ImageSet
    {
        return $this->multiply(array_values($heights), static fn(Output $spec, int $height): Output => $spec->with(
            transform: $spec->transform->withResize(static fn(?Resize $resize): Resize => ($resize ?? new Resize(height: $height))->with(height: $height)),
        ));
    }

    /**
     * Returns one image per format, preserving every other setting.
     */
    public function formats(Format ...$formats): ImageSet
    {
        return $this->multiply(array_values($formats), static fn(Output $spec, Format $format): Output => $spec->with(
            encoding: $spec->encoding->with(format: $format),
        ));
    }

    /**
     * Combines different outputs derived from the same configured source.
     */
    public function and(Image|ImageSet $other): ImageSet
    {
        if (!$this->sameSource($other)) {
            throw new InvalidArgumentException('An ImageSet can only combine outputs derived from the same source.');
        }

        if ($this->driver !== $other->driver || $this->limits != $other->limits) {
            throw new InvalidArgumentException('An ImageSet must use the same driver and limits. Configure the source before deriving its outputs.');
        }

        return new ImageSet($this->source, [...$this->specs, ...$other->specs], $this->limits, $this->driver);
    }

    protected function sameSource(AbstractImage $other): bool
    {
        return $this->source === $other->source || $this->source->signature() === $other->source->signature();
    }

    /**
     * @param non-empty-list<Output> $specs
     */
    protected function withSpecs(array $specs): static
    {
        return $this->recreate($specs, $this->limits, $this->driver);
    }

    /**
     * @param non-empty-list<Output> $specs
     */
    abstract protected function recreate(array $specs, ?Limits $limits, ?DriverInterface $driver): static;

    /**
     * @param \Closure(Output): Output $map
     */
    protected function mapSpecs(\Closure $map): static
    {
        return $this->withSpecs(array_map($map, $this->specs));
    }

    /**
     * @param \Closure(?Resize): Resize $mutate
     */
    private function reshape(\Closure $mutate): static
    {
        return $this->mapSpecs(static fn(Output $spec): Output => $spec->with(transform: $spec->transform->reshape($mutate)));
    }

    /**
     * @template T
     *
     * @param list<T>                   $values
     * @param \Closure(Output, T): Output $map
     */
    private function multiply(array $values, \Closure $map): ImageSet
    {
        if ([] === $values) {
            throw new InvalidArgumentException('Creating an ImageSet needs at least one value.');
        }

        $specs = [];

        foreach ($this->specs as $spec) {
            foreach ($values as $value) {
                $specs[] = $map($spec, $value);
            }
        }

        return new ImageSet($this->source, $specs, $this->limits, $this->driver);
    }

    protected function plan(): Plan
    {
        return Plan::negotiate($this->source, $this->specs, $this->driver, $this->limits);
    }

    protected function requireMeasurable(string $method, Output $spec): void
    {
        if ($spec->transform->isMeasurable()) {
            return;
        }

        if ($spec->transform->contains(Escape::class)) {
            throw \Alto\Image\Exception\UnmeasurableException::escaped($method);
        }

        throw \Alto\Image\Exception\UnmeasurableException::trimmed($method, (string) $spec->transform);
    }
}
