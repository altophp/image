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

namespace Alto\Image\Operation;

use Alto\Image\Anchor;
use Alto\Image\Colour;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Fit;
use Alto\Image\FocalPoint;
use Alto\Image\Focus;
use Alto\Image\Metadata;
use Alto\Image\Scaling;
use Alto\Image\Size;

/**
 * Resizes an image according to a fit and scaling policy.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Resize implements PortableOperationInterface, Solvable
{
    /**
     * @param float|null            $ratio      used when a box is not fully given, so that `cover(ratio: 16/9)`
     *                                          survives until `widths()` resolves it
     * @param int                   $background the pad colour for Contain, packed 0xAARRGGBB
     */
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public Fit $fit = Fit::Inside,
        public Anchor|Focus|FocalPoint $gravity = Anchor::Center,
        public Scaling $scaling = Scaling::Down,
        public ?float $ratio = null,
        public int $background = 0x00000000,
    ) {
        if (null === $width && null === $height && null === $ratio) {
            throw new InvalidArgumentException('Resize needs at least a width, a height or a ratio.');
        }

        if (null !== $width && $width < 1) {
            throw new InvalidArgumentException(\sprintf('Resize width must be at least 1 pixel, got %d.', $width));
        }

        if (null !== $height && $height < 1) {
            throw new InvalidArgumentException(\sprintf('Resize height must be at least 1 pixel, got %d.', $height));
        }

        if (null !== $ratio && (!is_finite($ratio) || $ratio <= 0.0)) {
            throw new InvalidArgumentException(\sprintf('Resize ratio must be positive, got %s.', $ratio));
        }
    }

    /**
     * Returns a copy with selected fields replaced.
     *
     * Changing one axis of a two-axis box recomputes the other from its ratio:
     *
     *     ->cover(ratio: 16/9)->widths(640, 960)   two 16:9 boxes, from the ratio
     *     ->cover(1280, 720)->widths(640)          640x360, from the box's own shape
     *
     * Without this adjustment, the second line would produce 640x720.
     */
    public function with(
        ?int $width = null,
        ?int $height = null,
        ?Fit $fit = null,
        Anchor|Focus|FocalPoint|null $gravity = null,
        ?Scaling $scaling = null,
        ?float $ratio = null,
        ?int $background = null,
    ): self {
        $newRatio = $ratio ?? $this->ratio;
        $shape = $newRatio ?? $this->shape();
        $newWidth = $width;
        $newHeight = $height;

        if (null === $newWidth) {
            $newWidth = null !== $height && null !== $shape
                ? max(1, (int) round($height * $shape))
                : $this->width;
        }

        if (null === $newHeight) {
            $newHeight = null !== $width && null !== $shape
                ? max(1, (int) round($width / $shape))
                : $this->height;
        }

        return new self(
            $newWidth,
            $newHeight,
            $fit ?? $this->fit,
            $gravity ?? $this->gravity,
            $scaling ?? $this->scaling,
            $newRatio,
            $background ?? $this->background,
        );
    }

    public function withoutRatio(): self
    {
        return new self(
            $this->width,
            $this->height,
            $this->fit,
            $this->gravity,
            $this->scaling,
            null,
            $this->background,
        );
    }

    /**
     * The aspect ratio this box already stands for, if it stands for one.
     */
    private function shape(): ?float
    {
        return null !== $this->width && null !== $this->height ? $this->width / $this->height : null;
    }

    public function project(Metadata $input): Metadata
    {
        $placement = $this->solve($input->size);

        return $input->with(
            size: $placement->output(),
            hasAlpha: $input->hasAlpha || ($placement->hasPad() && !Colour::isOpaque($this->background)),
        );
    }

    public function solve(Size $source): Placement
    {
        $box = $this->box($source);
        $scaleX = $box->width / $source->width;
        $scaleY = $box->height / $source->height;

        // Fill distorts each axis independently, so the scale policy applies per axis.
        if (Fit::Fill === $this->fit) {
            return new Placement(
                max(1, (int) round($source->width * $this->scaling->clamp($scaleX))),
                max(1, (int) round($source->height * $this->scaling->clamp($scaleY))),
            );
        }

        // Fill returned above, so the two arms below cover every remaining case.
        $needed = match ($this->fit) {
            Fit::Inside, Fit::Contain => min($scaleX, $scaleY),
            default => max($scaleX, $scaleY),
        };

        $allowed = $this->scaling->clamp($needed);
        $scaled = $source->scaledBy($allowed);

        if (Fit::Inside === $this->fit || Fit::Outside === $this->fit) {
            return Placement::scale($scaled);
        }

        // Preserve the requested aspect ratio when the scale policy clamps the
        // requested size by shrinking both target axes by the same factor.
        $target = $box->scaledBy($allowed / $needed);

        return Fit::Cover === $this->fit
            ? $this->crop($scaled, $target)
            : $this->pad($scaled, $target);
    }

    /**
     * Whether this resize pins the output size down whatever comes in.
     *
     * Only an exact fit with both axes named and no scale clamp can promise
     * that, and that is what lets `trim|cover=800x450,s:both` stay measurable
     * while `trim` on its own does not.
     */
    public function fixesOutput(): bool
    {
        return $this->fit->isExact()
            && null !== $this->width
            && null !== $this->height
            && Scaling::Both === $this->scaling;
    }

    public function __toString(): string
    {
        $parts = [$this->fit->value . '=' . ($this->width ?? '') . 'x' . ($this->height ?? '')];

        if (Anchor::Center !== $this->gravity) {
            $parts[] = 'g:' . ($this->gravity instanceof Anchor || $this->gravity instanceof Focus
                ? $this->gravity->value
                : (string) $this->gravity);
        }

        if (Scaling::Down !== $this->scaling) {
            $parts[] = 's:' . $this->scaling->value;
        }

        // Emit the ratio only while both dimensions remain unresolved. Once one
        // dimension is known, the WxH form carries the ratio.
        if (null !== $this->ratio && null === $this->width && null === $this->height) {
            $parts[] = 'r:' . json_encode($this->ratio, \JSON_THROW_ON_ERROR);
        }

        if (Fit::Contain === $this->fit && Colour::TRANSPARENT !== $this->background) {
            $parts[] = 'bg:' . Colour::format($this->background);
        }

        return implode(',', $parts);
    }

    public static function parse(array $arguments): static
    {
        [$width, $height] = array_pad(explode('x', $arguments['0'] ?? ''), 2, '');
        $gravity = $arguments['g'] ?? 'center';
        $ratio = $arguments['r'] ?? null;

        return new self(
            '' === $width ? null : (int) $width,
            '' === $height ? null : (int) $height,
            Fit::from($arguments['@'] ?? 'inside'),
            self::gravity($gravity),
            Scaling::from($arguments['s'] ?? 'down'),
            null === $ratio ? null : (float) $ratio,
            Colour::parse($arguments['bg'] ?? 'transparent'),
        );
    }

    private static function gravity(string $value): Anchor|Focus|FocalPoint
    {
        return Focus::tryFrom($value)
            ?? Anchor::tryFrom($value)
            ?? FocalPoint::parse($value);
    }

    /**
     * The box this resize is aiming at, resolved against the source.
     */
    private function box(Size $source): Size
    {
        if (null !== $this->width && null !== $this->height) {
            return new Size($this->width, $this->height);
        }

        if (null !== $this->width) {
            return new Size($this->width, null !== $this->ratio
                ? max(1, (int) round($this->width / $this->ratio))
                : max(1, (int) round($source->height * ($this->width / $source->width))));
        }

        if (null !== $this->height) {
            return new Size(null !== $this->ratio
                ? max(1, (int) round($this->height * $this->ratio))
                : max(1, (int) round($source->width * ($this->height / $source->height))), $this->height);
        }

        // A ratio on its own means the largest box of that ratio the source holds.
        $ratio = $this->ratio ?? $source->ratio();
        $width = $source->width;
        $height = max(1, (int) round($width / $ratio));

        if ($height > $source->height) {
            $height = $source->height;
            $width = max(1, (int) round($height * $ratio));
        }

        return new Size($width, $height);
    }

    private function crop(Size $scaled, Size $target): Placement
    {
        $crop = new Size(
            min($target->width, $scaled->width),
            min($target->height, $scaled->height),
        );

        // Skip a crop that covers the whole image so pass-through detection can
        // avoid decoding and re-encoding unchanged pixels.
        if ($crop->equals($scaled)) {
            return Placement::scale($scaled);
        }

        // Focus is the one strategy the pure layer cannot resolve: the SIZE stays
        // fixed and predictable, only the offset is left to the driver.
        [$x, $y] = $this->gravity instanceof Focus ? [null, null] : $this->gravity->offsetIn($scaled, $crop);

        return new Placement($scaled->width, $scaled->height, $crop->width, $crop->height, $x, $y);
    }

    private function pad(Size $scaled, Size $target): Placement
    {
        $outer = new Size(max($target->width, $scaled->width), max($target->height, $scaled->height));

        // Padding has no content to analyze, so a content-aware gravity centers.
        [$left, $top] = $this->gravity instanceof Focus
            ? Anchor::Center->offsetIn($outer, $scaled)
            : $this->gravity->offsetIn($outer, $scaled);

        return new Placement(
            $scaled->width,
            $scaled->height,
            padTop: $top,
            padRight: $outer->width - $scaled->width - $left,
            padBottom: $outer->height - $scaled->height - $top,
            padLeft: $left,
        );
    }
}
