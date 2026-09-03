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
 * Executes a negotiated operation sequence with GD.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GdPipeline
{
    /**
     * Walks the list and hands back what it produced, plus what it fudged.
     *
     * The degradations come back as a return value rather than through a
     * reference parameter, because a driver that reports "gd approximated blur
     * sigma 2.5 with three passes of its fixed 3x3 kernel" is saying something
     * the negotiation layer's "gd approximates blur=2.5" cannot: how far off.
     *
     * @param list<OperationInterface> $operations
     *
     * @return array{\GdImage, list<string>}
     */
    public function run(\GdImage $image, array $operations, bool $preserveInput = false): array
    {
        $degradations = [];
        $shared = $preserveInput;

        foreach ($operations as $operation) {
            // Solved here rather than handed down precomputed, so that what the
            // geometry is solved against is the raster that exists rather than a
            // size guessed before a rotation or an escape had its turn.
            $placement = $operation instanceof Solvable ? $operation->solve($this->size($image)) : null;

            // Geometry that changes the size creates its own destination canvas,
            // so it can read directly from a shared master without copying it.
            // No-op geometry and Orient leave the master shared until an
            // operation that may mutate it is reached.
            if ($shared && !$operation instanceof Orient) {
                $detaches = ($operation instanceof Resize || $operation instanceof Crop || $operation instanceof Extend)
                    && null !== $placement
                    && !$placement->isNoop($this->size($image));

                if ($detaches) {
                    $shared = false;
                } elseif (!$operation instanceof Resize && !$operation instanceof Crop && !$operation instanceof Extend) {
                    $image = $this->clone($image);
                    $shared = false;
                }
            }

            [$image, $note] = match (true) {
                $operation instanceof Resize => [$this->place($image, $placement, $operation->background, $this->focus($operation)), null],
                $operation instanceof Crop => [$this->place($image, $placement, Colour::TRANSPARENT, $this->focus($operation)), null],
                $operation instanceof Extend => [$this->place($image, $placement, $operation->background, null), null],
                $operation instanceof Trim => [$this->trim($image, $operation), null],
                $operation instanceof Rotate => [$this->rotate($image, $operation), null],
                $operation instanceof Flip => [$this->flip($image, $operation), null],
                $operation instanceof Orient => [$image, null],
                $operation instanceof Flatten => [$this->flatten($image, $operation->background), null],
                $operation instanceof Overlay => [$this->overlay($image, $operation), null],
                $operation instanceof Blur => $this->blur($image, $operation),
                $operation instanceof Sharpen => $this->sharpen($image, $operation),
                $operation instanceof Adjust => $this->adjust($image, $operation),
                $operation instanceof Grayscale => [$this->filter($image, \IMG_FILTER_GRAYSCALE), null],
                $operation instanceof Invert => [$this->filter($image, \IMG_FILTER_NEGATE), null],
                $operation instanceof Pixelate => [$this->filter($image, \IMG_FILTER_PIXELATE, $operation->size, true), null],
                $operation instanceof Tint => $this->tint($image, $operation),
                $operation instanceof Escape => [$this->escape($image, $operation), null],
                default => throw DriverException::failed('gd', 'walking the transform', \sprintf(
                    'It reached %s, which GdDriver::supports() should have refused. That is a bug in this driver, not in your call.',
                    $operation::class,
                )),
            };

            if (null !== $note) {
                $degradations[] = $note;
            }
        }

        // Encoding may adjust the returned raster even when every operation was
        // a no-op. Keep those changes away from the shared decoded master.
        if ($shared) {
            $image = $this->clone($image);
        }

        return [$image, $degradations];
    }

    public function size(\GdImage $image): Size
    {
        return new Size(imagesx($image), imagesy($image));
    }

    /**
     * The content-aware strategy requested by a geometry operation.
     */
    private function focus(Resize|Crop $operation): ?Focus
    {
        return $operation->gravity instanceof Focus ? $operation->gravity : null;
    }

    /**
     * Scale, crop and pad, in one call.
     *
     * imagecopyresampled() reads the required source rectangle directly into the
     * final canvas. This avoids intermediate rasters and discarded resampling.
     */
    public function place(\GdImage $image, ?Placement $placement, int $background, ?Focus $focus): \GdImage
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
        $output = $placement->output();
        $canvas = $this->canvas($output->width, $output->height, $background);

        // A pad with no scale and no crop is a copy, and imagecopy is the
        // cheaper way to say so.
        if ($window->isIdentity()) {
            imagecopy($canvas, $image, $placement->padLeft, $placement->padTop, 0, 0, $source->width, $source->height);

            return $canvas;
        }

        imagecopyresampled(
            $canvas,
            $image,
            $placement->padLeft,
            $placement->padTop,
            $window->sourceX,
            $window->sourceY,
            $window->width,
            $window->height,
            $window->sourceWidth,
            $window->sourceHeight,
        );

        return $canvas;
    }

    /**
     * Finds the most interesting window of the requested size.
     *
     * A 64-pixel thumbnail bounds the PHP scan to about four thousand iterations.
     *
     * @return array{int, int}
     */
    private function resolveFocus(\GdImage $image, Placement $placement, Focus $focus): array
    {
        // Placement offsets use scaled coordinates; focus scoring uses a thumbnail
        // of the same source.
        $cropWidth = $placement->cropWidth ?? $placement->scaleWidth;
        $cropHeight = $placement->cropHeight ?? $placement->scaleHeight;
        $slackX = $placement->scaleWidth - $cropWidth;
        $slackY = $placement->scaleHeight - $cropHeight;

        if ($slackX <= 0 && $slackY <= 0) {
            return [max(0, intdiv($slackX, 2)), max(0, intdiv($slackY, 2))];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, 64 / max($width, $height));
        $small = Resampler::scale($this->clone($image), max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        return [
            $this->bestOffset($this->columnScores($small, $focus), $cropWidth, $placement->scaleWidth, $slackX),
            $this->bestOffset($this->rowScores($small, $focus), $cropHeight, $placement->scaleHeight, $slackY),
        ];
    }

    /**
     * @param list<float> $scores one per row or column of the thumbnail
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

    /**
     * @return list<float>
     */
    private function columnScores(\GdImage $small, Focus $focus): array
    {
        $scores = [];

        for ($x = 0, $width = imagesx($small); $x < $width; ++$x) {
            $lumas = [];

            for ($y = 0, $height = imagesy($small); $y < $height; ++$y) {
                $lumas[] = $this->luma($small, $x, $y);
            }

            $scores[] = $this->score($lumas, $focus);
        }

        return $scores;
    }

    /**
     * @return list<float>
     */
    private function rowScores(\GdImage $small, Focus $focus): array
    {
        $scores = [];

        for ($y = 0, $height = imagesy($small); $y < $height; ++$y) {
            $lumas = [];

            for ($x = 0, $width = imagesx($small); $x < $width; ++$x) {
                $lumas[] = $this->luma($small, $x, $y);
            }

            $scores[] = $this->score($lumas, $focus);
        }

        return $scores;
    }

    /**
     * Entropy scores information; attention scores local change, which is what
     * an edge is, and edges are where the subject usually is.
     *
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

        $total = \count($lumas);
        $entropy = 0.0;

        foreach ($histogram as $bin) {
            if ($bin > 0) {
                $probability = $bin / $total;
                $entropy -= $probability * log($probability, 2);
            }
        }

        return $entropy;
    }

    private function luma(\GdImage $image, int $x, int $y): float
    {
        $colour = imagecolorat($image, $x, $y);

        return 0.2126 * (($colour >> 16) & 0xFF) + 0.7152 * (($colour >> 8) & 0xFF) + 0.0722 * ($colour & 0xFF);
    }

    /**
     * Removes a uniform border by walking inwards from each edge.
     *
     * The walk stops at the first row or column that is not the background, so a
     * bordered image costs the border and not the picture. The pathological case
     * is an image that is entirely background, and that one exits with a 1x1
     * result rather than looping.
     */
    private function trim(\GdImage $image, Trim $operation): \GdImage
    {
        $reference = $operation->background ?? $this->pixel($image, 0, 0);
        $threshold = $operation->threshold;
        $nativeReference = null === $operation->background
            ? imagecolorat($image, 0, 0)
            : $this->allocate($image, $operation->background);

        if (false === $nativeReference) {
            // @codeCoverageIgnoreStart
            throw DriverException::failed('gd', 'reading the trim reference pixel');
            // @codeCoverageIgnoreEnd
        }

        $coarse = imagecropauto($image, \IMG_CROP_THRESHOLD, \PHP_FLOAT_EPSILON, $nativeReference);

        // Removing exact background pixels in C is a safe lower bound for every
        // channel tolerance. The precise scan below only has the fuzzy edge left.
        if ($coarse instanceof \GdImage) {
            $image = $coarse;
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $top = 0;
        while ($top < $height - 1 && $this->rowIs($image, $top, $width, $reference, $threshold)) {
            ++$top;
        }

        $bottom = $height - 1;
        while ($bottom > $top && $this->rowIs($image, $bottom, $width, $reference, $threshold)) {
            --$bottom;
        }

        $left = 0;
        while ($left < $width - 1 && $this->columnIs($image, $left, $top, $bottom, $reference, $threshold)) {
            ++$left;
        }

        $right = $width - 1;
        while ($right > $left && $this->columnIs($image, $right, $top, $bottom, $reference, $threshold)) {
            --$right;
        }

        if (0 === $top && 0 === $left && $right === $width - 1 && $bottom === $height - 1) {
            return $image;
        }

        $cropped = imagecrop($image, ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1]);

        if (false === $cropped) {
            // @codeCoverageIgnoreStart
            throw DriverException::failed('gd', 'trimming the border');
            // @codeCoverageIgnoreEnd
        }

        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);

        return $cropped;
    }

    private function rowIs(\GdImage $image, int $y, int $width, int $reference, int $threshold): bool
    {
        for ($x = 0; $x < $width; ++$x) {
            if (!$this->near($this->pixel($image, $x, $y), $reference, $threshold)) {
                return false;
            }
        }

        return true;
    }

    private function columnIs(\GdImage $image, int $x, int $top, int $bottom, int $reference, int $threshold): bool
    {
        for ($y = $top; $y <= $bottom; ++$y) {
            if (!$this->near($this->pixel($image, $x, $y), $reference, $threshold)) {
                return false;
            }
        }

        return true;
    }

    private function near(int $left, int $right, int $threshold): bool
    {
        return abs(Colour::red($left) - Colour::red($right)) <= $threshold
            && abs(Colour::green($left) - Colour::green($right)) <= $threshold
            && abs(Colour::blue($left) - Colour::blue($right)) <= $threshold
            && abs(Colour::alpha($left) - Colour::alpha($right)) <= $threshold;
    }

    /**
     * Rotates, then conforms to the size the projection promised.
     *
     * GD ceils its own bounding box and ImageMagick computes another one again,
     * and the two disagree by up to two pixels at the same angle. Since size()
     * has to answer one number before anything decodes, the projection decides
     * and this trims or pads the difference. It is at most a pixel of edge, and
     * it is what makes projected and actual the same number on every driver.
     */
    private function rotate(\GdImage $image, Rotate $operation): \GdImage
    {
        if (0.0 === $operation->degrees) {
            return $image;
        }

        $wanted = $operation->boundingBox(new Size(imagesx($image), imagesy($image)));

        // GD measures anticlockwise and every other image API measures clockwise.
        $rotated = imagerotate($image, 360.0 - $operation->degrees, $this->allocate($image, $operation->background));

        if (false === $rotated) {
            // @codeCoverageIgnoreStart
            throw DriverException::failed('gd', \sprintf('rotating by %s degrees', $operation->degrees));
            // @codeCoverageIgnoreEnd
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $this->conform($rotated, $wanted, $operation->background);
    }

    /**
     * Center-crops or center-pads to an exact size.
     */
    public function conform(\GdImage $image, Size $wanted, int $background): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width === $wanted->width && $height === $wanted->height) {
            return $image;
        }

        $canvas = $this->canvas($wanted->width, $wanted->height, $background);
        $copyWidth = min($width, $wanted->width);
        $copyHeight = min($height, $wanted->height);

        imagecopy(
            $canvas,
            $image,
            intdiv($wanted->width - $copyWidth, 2),
            intdiv($wanted->height - $copyHeight, 2),
            intdiv($width - $copyWidth, 2),
            intdiv($height - $copyHeight, 2),
            $copyWidth,
            $copyHeight,
        );

        return $canvas;
    }

    private function flip(\GdImage $image, Flip $operation): \GdImage
    {
        imageflip($image, $operation->vertical ? \IMG_FLIP_VERTICAL : \IMG_FLIP_HORIZONTAL);

        return $image;
    }

    private function flatten(\GdImage $image, int $background): \GdImage
    {
        $canvas = $this->canvas(imagesx($image), imagesy($image), $background);
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagealphablending($canvas, false);

        return $canvas;
    }

    private function overlay(\GdImage $image, Overlay $operation): \GdImage
    {
        $bytes = @file_get_contents($operation->path);

        if (false === $bytes) {
            throw DriverException::failed('gd', 'reading the overlay', \sprintf('"%s" is not readable.', $operation->path));
        }

        $mark = imagecreatefromstring($bytes);

        if (false === $mark) {
            throw DriverException::failed('gd', 'decoding the overlay', \sprintf('"%s" is not an image GD can read.', $operation->path));
        }

        imagealphablending($mark, false);
        imagesavealpha($mark, true);

        $inner = new Size(imagesx($mark), imagesy($mark));
        $outer = new Size(
            max(1, imagesx($image) - 2 * $operation->margin),
            max(1, imagesy($image) - 2 * $operation->margin),
        );
        [$x, $y] = $operation->gravity->offsetIn($outer, $inner);

        imagealphablending($image, true);

        if (1.0 === $operation->opacity) {
            imagecopy($image, $mark, $x + $operation->margin, $y + $operation->margin, 0, 0, $inner->width, $inner->height);
        } else {
            imagecopymerge(
                $image,
                $mark,
                $x + $operation->margin,
                $y + $operation->margin,
                0,
                0,
                $inner->width,
                $inner->height,
                (int) round($operation->opacity * 100),
            );
        }

        imagealphablending($image, false);

        return $image;
    }

    /**
     * GD's gaussian blur takes no radius at all, so a sigma is approximated by
     * applying the fixed 3x3 kernel a computed number of times.
     *
     * This is the operation that made Support three-valued. A boolean would have
     * forced GD to either claim it blurs by sigma, which it does not, or refuse
     * to blur at all, which is worse than blurring roughly.
     */
    /**
     * @return array{\GdImage, string}
     */
    private function blur(\GdImage $image, Blur $operation): array
    {
        // Three fixed-kernel passes converge on a gaussian. Bound the count so
        // a hostile sigma cannot turn an approximation into an unbounded loop.
        $passes = max(1, min(64, (int) round(($operation->sigma / 0.85) ** 2)));

        for ($i = 0; $i < $passes; ++$i) {
            imagefilter($image, \IMG_FILTER_GAUSSIAN_BLUR);
        }

        return [$image, \sprintf(
            'gd approximated blur sigma %s with %d pass(es) of its fixed 3x3 kernel',
            $operation->sigma,
            $passes,
        )];
    }

    /**
     * @return array{\GdImage, string}
     */
    private function sharpen(\GdImage $image, Sharpen $operation): array
    {
        $amount = $operation->amount / max(1.0, $operation->sigma);
        $center = 1.0 + 4.0 * $amount;
        $side = -$amount;

        imageconvolution($image, [
            [0.0, $side, 0.0],
            [$side, $center, $side],
            [0.0, $side, 0.0],
        ], 1.0, 0.0);

        return [$image, \sprintf(
            'gd approximated unsharp mask sigma %s amount %s with a 3x3 convolution',
            $operation->sigma,
            $operation->amount,
        )];
    }

    /**
     * @return array{\GdImage, string|null}
     */
    private function adjust(\GdImage $image, Adjust $operation): array
    {
        if ($operation->isNoop()) {
            return [$image, null];
        }

        if (0 !== $operation->brightness) {
            // GD's range is [-255, 255] and the operation's is [-100, 100].
            imagefilter($image, \IMG_FILTER_BRIGHTNESS, (int) round($operation->brightness * 2.55));
        }

        if (0 !== $operation->contrast) {
            // GD's contrast runs backwards: a positive value reduces it.
            imagefilter($image, \IMG_FILTER_CONTRAST, -$operation->contrast);
        }

        if (1.0 !== $operation->gamma) {
            imagegammacorrect($image, 1.0, $operation->gamma);
        }

        if (0 !== $operation->saturation) {
            $this->saturate($image, $operation->saturation);
        }

        return [$image, 'gd approximated the tone adjustment; its brightness and contrast curves are its own'];
    }

    /**
     * GD has no saturation filter, so this is the one per-pixel loop in the driver.
     *
     * Runs only for a non-zero saturation adjustment.
     */
    private function saturate(\GdImage $image, int $saturation): void
    {
        $factor = 1.0 + $saturation / 100;
        $width = imagesx($image);
        $height = imagesy($image);

        imagealphablending($image, false);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $colour = imagecolorat($image, $x, $y);
                $alpha = ($colour >> 24) & 0x7F;
                $red = ($colour >> 16) & 0xFF;
                $green = ($colour >> 8) & 0xFF;
                $blue = $colour & 0xFF;
                $luma = 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;

                imagesetpixel($image, $x, $y, ($alpha << 24)
                    | (self::clamp($luma + ($red - $luma) * $factor) << 16)
                    | (self::clamp($luma + ($green - $luma) * $factor) << 8)
                    | self::clamp($luma + ($blue - $luma) * $factor));
            }
        }
    }

    /**
     * @return array{\GdImage, string}
     */
    private function tint(\GdImage $image, Tint $operation): array
    {
        imagefilter($image, \IMG_FILTER_GRAYSCALE);
        imagefilter(
            $image,
            \IMG_FILTER_COLORIZE,
            (int) round((Colour::red($operation->colour) - 128) * $operation->strength),
            (int) round((Colour::green($operation->colour) - 128) * $operation->strength),
            (int) round((Colour::blue($operation->colour) - 128) * $operation->strength),
        );

        return [$image, 'gd approximated the tint by desaturating and colorizing'];
    }

    private function filter(\GdImage $image, int $filter, ?int $argument = null, bool $extra = false): \GdImage
    {
        if (null === $argument) {
            imagefilter($image, $filter);

            return $image;
        }

        imagefilter($image, $filter, $argument, $extra);

        return $image;
    }

    private function escape(\GdImage $image, Escape $operation): \GdImage
    {
        $returned = ($operation->handler)($image);

        if (!$returned instanceof \GdImage) {
            throw DriverException::failed('gd', 'running the "' . $operation->label . '" Escape', \sprintf(
                'The closure returned %s. A GD escape takes a GdImage and returns one.',
                get_debug_type($returned),
            ));
        }

        return $returned;
    }

    /**
     * A transparent-capable canvas filled with one colour.
     */
    public function canvas(int $width, int $height, int $background): \GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, max(1, $width) - 1, max(1, $height) - 1, $this->allocate($canvas, $background));

        return $canvas;
    }

    /**
     * Converts this package's 0xAARRGGBB, where 255 is opaque, into GD's, where
     * alpha runs 0 to 127 and 0 is opaque.
     */
    public function allocate(\GdImage $image, int $packed): int
    {
        $allocated = imagecolorallocatealpha(
            $image,
            Colour::red($packed),
            Colour::green($packed),
            Colour::blue($packed),
            max(0, min(127, 127 - (int) round(Colour::alpha($packed) * 127 / 255))),
        );

        return false === $allocated ? 0 : $allocated;
    }

    private function pixel(\GdImage $image, int $x, int $y): int
    {
        $colour = imagecolorat($image, $x, $y);
        $alpha = 255 - (int) round((($colour >> 24) & 0x7F) * 255 / 127);

        return ($alpha << 24) | ($colour & 0xFFFFFF);
    }

    private function clone(\GdImage $image): \GdImage
    {
        $copy = $this->canvas(imagesx($image), imagesy($image), Colour::TRANSPARENT);
        imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $copy;
    }

    private static function clamp(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }
}
