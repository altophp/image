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

use Alto\Image\Colour;
use Alto\Image\Exception\DriverException;
use Alto\Image\Focus;
use Alto\Image\Internal\Window;
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
use Alto\Image\Size;

/**
 * Executes a negotiated operation sequence with Imagick.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ImagickPipeline
{
    /**
     * Applies the operations and returns the image with any degradation notes.
     *
     * @param list<OperationInterface> $operations
     *
     * @return array{\Imagick, list<string>}
     */
    public function run(\Imagick $image, array $operations): array
    {
        $degradations = [];

        foreach ($operations as $operation) {
            // Solved against the raster that exists, for the same reason GdPipeline does.
            $placement = $operation instanceof Solvable ? $operation->solve($this->size($image)) : null;

            [$image, $note] = match (true) {
                $operation instanceof Resize => [$this->place($image, $placement, $operation->background, $this->focus($operation)), null],
                $operation instanceof Crop => [$this->place($image, $placement, Colour::TRANSPARENT, $this->focus($operation)), null],
                $operation instanceof Extend => [$this->place($image, $placement, $operation->background, null), null],
                $operation instanceof Trim => [$this->trim($image, $operation), null],
                $operation instanceof Rotate => $this->rotate($image, $operation),
                $operation instanceof Flip => [$this->flip($image, $operation), null],
                $operation instanceof Orient => [$image, null],
                $operation instanceof Flatten => [$this->flatten($image, $operation->background), null],
                $operation instanceof Overlay => [$this->overlay($image, $operation), null],
                $operation instanceof Blur => [$this->blur($image, $operation), null],
                $operation instanceof Sharpen => [$this->sharpen($image, $operation), null],
                $operation instanceof Adjust => [$this->adjust($image, $operation), null],
                $operation instanceof Grayscale => [$this->grayscale($image), null],
                $operation instanceof Invert => [$this->invert($image), null],
                $operation instanceof Pixelate => [$this->pixelate($image, $operation), null],
                $operation instanceof Tint => [$this->tint($image, $operation), null],
                $operation instanceof IccConvert => $this->icc($image, $operation),
                $operation instanceof Escape => [$this->escape($image, $operation), null],
                default => throw DriverException::failed('imagick', 'walking the transform', \sprintf(
                    'It reached %s, which ImagickDriver::supports() should have refused. That is a bug in this driver.',
                    $operation::class,
                )),
            };

            if (null !== $note) {
                $degradations[] = $note;
            }
        }

        return [$image, $degradations];
    }

    /**
     * Crop first, then scale, then pad. The order matters.
     *
     * Scaling before cropping resamples pixels the crop then discards: a cover
     * from 2400x1600 into 1200x675 computes 19 per cent more pixels than it
     * keeps, and a 1000x4000 portrait into the same box computes 86 per cent
     * more. Cropping first is free, because it is a rectangle of the buffer, and
     * it means every pixel the resampler touches is a pixel that survives.
     *
     * Placement expresses the crop in scaled coordinates, so Window maps it back
     * onto the source while preserving the projected output size.
     */
    public function place(\Imagick $image, ?Placement $placement, int $background, ?Focus $focus): \Imagick
    {
        if (null === $placement) {
            return $image;
        }

        $source = $this->size($image);

        if ($placement->isNoop($source)) {
            return $image;
        }

        [$cropX, $cropY] = $placement->cropIsDeferred()
            ? $this->resolveFocus($image, $placement, $focus ?? Focus::Attention)
            : [$placement->cropX ?? 0, $placement->cropY ?? 0];

        $window = Window::of($placement, $source, $cropX, $cropY);

        if ($window->sourceWidth !== $source->width || $window->sourceHeight !== $source->height) {
            $image->cropImage($window->sourceWidth, $window->sourceHeight, $window->sourceX, $window->sourceY);
            $image->setImagePage(0, 0, 0, 0);
        }

        if ($window->sourceWidth !== $window->width || $window->sourceHeight !== $window->height) {
            // Area averaging avoids filter ringing and keeps Imagick output close
            // to GD's imagecopyresampled() result.
            $image->scaleImage($window->width, $window->height);
            $image->setImagePage(0, 0, 0, 0);
        }

        return $placement->hasPad() ? $this->pad($image, $placement, $background) : $image;
    }

    private function pad(\Imagick $image, Placement $placement, int $background): \Imagick
    {
        $image->setImageBackgroundColor($this->pixel($background));

        // A negative offset in extentImage means "add space on that side", which
        // is the opposite of what the name suggests.
        $image->extentImage(
            $image->getImageWidth() + $placement->padLeft + $placement->padRight,
            $image->getImageHeight() + $placement->padTop + $placement->padBottom,
            -$placement->padLeft,
            -$placement->padTop,
        );
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    /**
     * Finds the most interesting window, on a 64-pixel thumbnail.
     *
     * @return array{int, int}
     */
    private function resolveFocus(\Imagick $image, Placement $placement, Focus $focus): array
    {
        // The offsets Placement speaks in are scaled coordinates, so the slack is
        // measured there. The scoring runs on a thumbnail of the source, which is
        // the same picture and a great deal less of it.
        $cropWidth = $placement->cropWidth ?? $placement->scaleWidth;
        $cropHeight = $placement->cropHeight ?? $placement->scaleHeight;
        $slackX = $placement->scaleWidth - $cropWidth;
        $slackY = $placement->scaleHeight - $cropHeight;

        if ($slackX <= 0 && $slackY <= 0) {
            return [max(0, intdiv($slackX, 2)), max(0, intdiv($slackY, 2))];
        }

        $size = $this->size($image);
        $small = clone $image;
        $scale = min(1.0, 64 / max($size->width, $size->height));
        $small->scaleImage(
            max(1, (int) round($size->width * $scale)),
            max(1, (int) round($size->height * $scale)),
        );

        $lumas = $this->lumaGrid($small);
        $small->clear();

        return [
            $this->bestOffset($this->columnScores($lumas, $focus), $cropWidth, $placement->scaleWidth, $slackX),
            $this->bestOffset($this->rowScores($lumas, $focus), $cropHeight, $placement->scaleHeight, $slackY),
        ];
    }

    /**
     * @return list<list<float>> rows of luma
     */
    private function lumaGrid(\Imagick $small): array
    {
        $width = $small->getImageWidth();
        $height = $small->getImageHeight();
        $raw = $small->exportImagePixels(0, 0, $width, $height, 'RGB', \Imagick::PIXEL_CHAR);
        $grid = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = [];

            for ($x = 0; $x < $width; ++$x) {
                $at = ($y * $width + $x) * 3;
                $row[] = 0.2126 * (float) ($raw[$at] ?? 0)
                    + 0.7152 * (float) ($raw[$at + 1] ?? 0)
                    + 0.0722 * (float) ($raw[$at + 2] ?? 0);
            }

            $grid[] = $row;
        }

        return $grid;
    }

    /**
     * @param list<list<float>> $grid
     *
     * @return list<float>
     */
    private function columnScores(array $grid, Focus $focus): array
    {
        $scores = [];

        for ($x = 0, $width = \count($grid[0] ?? []); $x < $width; ++$x) {
            $scores[] = $this->score(array_column($grid, $x), $focus);
        }

        return $scores;
    }

    /**
     * @param list<list<float>> $grid
     *
     * @return list<float>
     */
    private function rowScores(array $grid, Focus $focus): array
    {
        return array_map(fn(array $row): float => $this->score($row, $focus), $grid);
    }

    /**
     * @param list<float> $lumas
     */
    private function score(array $lumas, Focus $focus): float
    {
        if (Focus::Attention === $focus) {
            $sum = 0.0;

            for ($i = 1, $count = \count($lumas); $i < $count; ++$i) {
                $sum += abs($lumas[$i] - $lumas[$i - 1]);
            }

            return $sum;
        }

        $histogram = array_fill(0, 16, 0);

        foreach ($lumas as $luma) {
            ++$histogram[min(15, (int) ($luma / 16))];
        }

        $total = max(1, \count($lumas));
        $entropy = 0.0;

        foreach ($histogram as $bin) {
            if ($bin > 0) {
                $probability = $bin / $total;
                $entropy -= $probability * log($probability, 2);
            }
        }

        return $entropy;
    }

    /**
     * @param list<float> $scores
     */
    private function bestOffset(array $scores, int $window, int $full, int $slack): int
    {
        if ($slack <= 0 || [] === $scores) {
            return 0;
        }

        $count = \count($scores);
        $span = max(1, (int) round($window * $count / $full));
        $running = array_sum(\array_slice($scores, 0, $span));
        $best = $running;
        $bestAt = 0;

        for ($at = 1; $at + $span <= $count; ++$at) {
            $running += $scores[$at + $span - 1] - $scores[$at - 1];

            if ($running > $best) {
                $best = $running;
                $bestAt = $at;
            }
        }

        return max(0, min($slack, (int) round($bestAt * $full / $count)));
    }

    private function trim(\Imagick $image, Trim $operation): \Imagick
    {
        if (null !== $operation->background) {
            $image->setImageBackgroundColor($this->pixel($operation->background));
        }

        // Imagick's fuzz is a fraction of the quantum range, and the operation's
        // threshold is a channel tolerance out of 255.
        $image->trimImage($operation->threshold / 255 * $image->getQuantumRange()['quantumRangeLong']);
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    /**
     * Rotates, then conforms to the size the projection promised.
     *
     * ImageMagick may produce a bounding box up to two pixels larger than the
     * projected ceil(w|cos| + h|sin|) size. The pipeline removes that difference.
     *
     * @return array{\Imagick, string|null}
     */
    private function rotate(\Imagick $image, Rotate $operation): array
    {
        if (0.0 === $operation->degrees) {
            return [$image, null];
        }

        $wanted = $operation->boundingBox($this->size($image));

        $image->rotateImage($this->pixel($operation->background), $operation->degrees);
        $image->setImagePage(0, 0, 0, 0);

        $produced = $this->size($image);

        if ($produced->equals($wanted)) {
            return [$image, null];
        }

        return [$this->conform($image, $wanted, $operation->background), \sprintf(
            'imagick rotated %s degrees into a %s box and it was trimmed to the projected %s',
            $operation->degrees,
            $produced,
            $wanted,
        )];
    }

    /**
     * Center-crops or center-pads to an exact size.
     */
    public function conform(\Imagick $image, Size $wanted, int $background): \Imagick
    {
        $size = $this->size($image);

        if ($size->equals($wanted)) {
            return $image;
        }

        $image->setImageBackgroundColor($this->pixel($background));
        $image->extentImage(
            $wanted->width,
            $wanted->height,
            intdiv($size->width - $wanted->width, 2),
            intdiv($size->height - $wanted->height, 2),
        );
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    private function flip(\Imagick $image, Flip $operation): \Imagick
    {
        if ($operation->vertical) {
            $image->flipImage();
        } else {
            $image->flopImage();
        }

        return $image;
    }

    private function flatten(\Imagick $image, int $background): \Imagick
    {
        $canvas = new \Imagick();
        $canvas->newImage($image->getImageWidth(), $image->getImageHeight(), $this->pixel($background));
        $canvas->setImageFormat($image->getImageFormat());
        $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, 0, 0);

        if (Colour::isOpaque($background)) {
            $canvas->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        }

        $image->clear();

        return $canvas;
    }

    private function overlay(\Imagick $image, Overlay $operation): \Imagick
    {
        $mark = new \Imagick();

        try {
            $mark->readImage($operation->path);
        } catch (\ImagickException $error) {
            throw DriverException::failed('imagick', 'reading the overlay', \sprintf(
                '"%s": %s',
                $operation->path,
                $error->getMessage(),
            ));
        }

        if (1.0 !== $operation->opacity) {
            $mark->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
            $mark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $operation->opacity, \Imagick::CHANNEL_ALPHA);
        }

        $inner = new Size($mark->getImageWidth(), $mark->getImageHeight());
        $outer = new Size(
            max(1, $image->getImageWidth() - 2 * $operation->margin),
            max(1, $image->getImageHeight() - 2 * $operation->margin),
        );
        [$x, $y] = $operation->gravity->offsetIn($outer, $inner);

        $image->compositeImage($mark, \Imagick::COMPOSITE_OVER, $x + $operation->margin, $y + $operation->margin);
        $mark->clear();

        return $image;
    }

    private function blur(\Imagick $image, Blur $operation): \Imagick
    {
        // A 3.5 sigma radius discards less than 0.05% of the Gaussian tail.
        // Supplying it avoids ImageMagick's wider automatic kernel, while the
        // separable filter avoids the much slower two-dimensional convolution.
        $image->blurImage(ceil(3.5 * $operation->sigma), $operation->sigma);

        return $image;
    }

    private function sharpen(\Imagick $image, Sharpen $operation): \Imagick
    {
        $image->unsharpMaskImage(0.0, $operation->sigma, $operation->amount, 0.0);

        return $image;
    }

    private function adjust(\Imagick $image, Adjust $operation): \Imagick
    {
        if ($operation->isNoop()) {
            return $image;
        }

        if (0 !== $operation->brightness || 0 !== $operation->contrast) {
            $image->brightnessContrastImage($operation->brightness, $operation->contrast);
        }

        if (0 !== $operation->saturation) {
            // modulateImage takes percentages where 100 leaves the channel alone.
            $image->modulateImage(100, 100 + $operation->saturation, 100);
        }

        if (1.0 !== $operation->gamma) {
            $image->gammaImage($operation->gamma);
        }

        return $image;
    }

    private function grayscale(\Imagick $image): \Imagick
    {
        // Desaturate while retaining sRGB and the projected colour space.
        $image->modulateImage(100, 0, 100);

        return $image;
    }

    private function invert(\Imagick $image): \Imagick
    {
        // CHANNEL_DEFAULT excludes alpha; inverting transparency would turn a
        // cut-out into a silhouette.
        $image->negateImage(false, \Imagick::CHANNEL_DEFAULT);

        return $image;
    }

    private function pixelate(\Imagick $image, Pixelate $operation): \Imagick
    {
        $size = $this->size($image);
        $small = new Size(
            max(1, intdiv($size->width, $operation->size)),
            max(1, intdiv($size->height, $operation->size)),
        );

        $image->resizeImage($small->width, $small->height, \Imagick::FILTER_BOX, 1.0);
        $image->resizeImage($size->width, $size->height, \Imagick::FILTER_POINT, 1.0);
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    private function tint(\Imagick $image, Tint $operation): \Imagick
    {
        // Use the same two steps as GD to keep both driver outputs aligned.
        $image->modulateImage(100, 0, 100);
        $blend = (int) round(0xFF * $operation->strength);
        $opacity = 0xFF000000 | ($blend << 16) | ($blend << 8) | $blend;
        $image->tintImage($this->pixel($operation->colour), $this->pixel($opacity));

        return $image;
    }

    /**
     * @return array{\Imagick, string|null}
     */
    private function icc(\Imagick $image, IccConvert $operation): array
    {
        $named = match (strtolower($operation->profile)) {
            'srgb' => \Imagick::COLORSPACE_SRGB,
            'gray', 'grey' => \Imagick::COLORSPACE_GRAY,
            'cmyk' => \Imagick::COLORSPACE_CMYK,
            default => null,
        };

        if (null !== $named) {
            $image->transformImageColorspace($named);

            return [$image, null];
        }

        $profile = @file_get_contents($operation->profile);

        if (false === $profile) {
            throw DriverException::failed('imagick', 'reading the ICC profile', \sprintf(
                '"%s" is neither a known name nor a readable file.',
                $operation->profile,
            ));
        }

        try {
            $image->profileImage('icc', $profile);
        } catch (\ImagickException $error) {
            return [$image, 'imagick could not apply the ICC profile: ' . $error->getMessage()];
        }

        return [$image, null];
    }

    private function escape(\Imagick $image, Escape $operation): \Imagick
    {
        $returned = ($operation->handler)($image);

        if (!$returned instanceof \Imagick) {
            throw DriverException::failed('imagick', 'running the "' . $operation->label . '" Escape', \sprintf(
                'The closure returned %s. An Imagick escape takes an Imagick and returns one.',
                get_debug_type($returned),
            ));
        }

        return $returned;
    }

    /**
     * Converts this package's 0xAARRGGBB into an ImagickPixel.
     *
     * Opaque colours use rgb() because rgba() activates ImageMagick's alpha
     * channel even when alpha is 1, increasing opaque PNG output size.
     */
    public function pixel(int $packed): \ImagickPixel
    {
        if (Colour::isOpaque($packed)) {
            return new \ImagickPixel(\sprintf(
                'rgb(%d, %d, %d)',
                Colour::red($packed),
                Colour::green($packed),
                Colour::blue($packed),
            ));
        }

        return new \ImagickPixel(\sprintf(
            'rgba(%d, %d, %d, %s)',
            Colour::red($packed),
            Colour::green($packed),
            Colour::blue($packed),
            json_encode(round(Colour::alpha($packed) / 255, 4), \JSON_THROW_ON_ERROR),
        ));
    }

    public function size(\Imagick $image): Size
    {
        return new Size($image->getImageWidth(), $image->getImageHeight());
    }

    private function focus(Resize|Crop $operation): ?Focus
    {
        return $operation->gravity instanceof Focus ? $operation->gravity : null;
    }
}
