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

namespace Alto\Image\Driver;

use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Exception\UnsupportedOperationException;
use Alto\Image\Format;
use Alto\Image\Limits;
use Alto\Image\Metadata;
use Alto\Image\Operation\OperationInterface;
use Alto\Image\Operation\Orient;
use Alto\Image\Operation\Solvable;
use Alto\Image\Source;

/**
 * A source, driver, and ordered outputs negotiated for one processing pass.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Plan
{
    /**
     * @param Metadata       $input        the source, already oriented
     * @param list<Output>   $requests
     * @param list<Metadata> $outputs      one per requested output, same order
     * @param list<string>   $degradations what the chosen driver said it could only approximate
     */
    private function __construct(
        public DriverInterface $driver,
        public Source $source,
        public Metadata $input,
        public array $requests,
        public array $outputs,
        public Limits $limits,
        public array $degradations = [],
    ) {}

    /**
     * Chooses a driver that can do all of it, and works out what all of it produces.
     *
     * @param list<Output>               $specs
     * @param list<DriverInterface>|null $candidates null asks what is installed
     *
     * @throws UnsupportedOperationException when nothing installed can do the work
     */
    public static function negotiate(
        Source $source,
        array $specs,
        ?DriverInterface $driver = null,
        ?Limits $limits = null,
        ?array $candidates = null,
    ): self {
        if ([] === $specs) {
            throw new \Alto\Image\Exception\InvalidArgumentException('A Plan needs at least one output.');
        }

        $limits ??= new Limits();
        $raw = $source->metadata();
        $limits->check($raw, $source->origin());
        $limits->checkComplete($raw, $source->tail(), $source->origin());

        $input = $raw->oriented();
        // Escape cannot be projected, but driver selection and limits still need
        // the metadata known before it.
        $outputs = array_map(static fn(Output $spec): Metadata => $spec->estimate($input), $specs);

        foreach ($outputs as $output) {
            $limits->checkOutput($output, $source->origin() . ' projected output');
        }

        $pool = null !== $driver ? [$driver] : ($candidates ?? self::installed());
        $refusals = [];
        $best = null;

        foreach ($pool as $candidate) {
            $verdict = self::interview($candidate, $input, $specs);

            if (\is_string($verdict)) {
                $refusals[] = \sprintf('%-42s %s', $candidate::class . ':', $verdict);

                continue;
            }

            // Prefer an exact driver and retain the first approximate candidate
            // only as a fallback.
            if ([] === $verdict) {
                return new self($candidate, $source, $input, $specs, $outputs, $limits);
            }

            $best ??= [$candidate, $verdict];
        }

        if (null !== $best) {
            return new self($best[0], $source, $input, $specs, $outputs, $limits, $best[1]);
        }

        throw UnsupportedOperationException::noDriverFor(
            self::describe($input, $specs),
            $refusals,
            self::remedy($input, $specs, $refusals),
        );
    }

    /**
     * Returns the projected metadata for one requested output.
     */
    public function output(int $index): Metadata
    {
        if (!isset($this->outputs[$index])) {
            throw new \Alto\Image\Exception\InvalidArgumentException(\sprintf('Plan output index %d does not exist.', $index));
        }

        return $this->outputs[$index];
    }

    /**
     * The operations for one requested output, in order.
     *
     * Operations remain unsolved so each geometry step uses the current raster
     * size. This stays correct after rotations and stops projection after Escape.
     *
     * @return list<OperationInterface>
     */
    public function operations(int $index): array
    {
        if (!isset($this->requests[$index])) {
            throw new \Alto\Image\Exception\InvalidArgumentException(\sprintf('Plan output index %d does not exist.', $index));
        }

        return $this->requests[$index]->transform->operations;
    }

    /**
     * Whether this output can copy the source bytes unchanged.
     */
    public function isPassThrough(int $index): bool
    {
        if (!isset($this->requests[$index])) {
            throw new \Alto\Image\Exception\InvalidArgumentException(\sprintf('Plan output index %d does not exist.', $index));
        }

        $spec = $this->requests[$index];

        // Trim and Escape prevent an exact comparison with the source.
        if (!$spec->transform->isMeasurable()) {
            return false;
        }

        if (!$spec->encoding->isPassThrough($this->input)) {
            return false;
        }

        // An orientation the source still carries has to be baked into the
        // pixels, so those bytes are not the bytes that were asked for.
        if (1 !== $this->source->metadata()->orientation) {
            return false;
        }

        foreach ($this->operations($index) as $operation) {
            // A Plan orients before it projects, so an explicit Orient in the
            // chain has nothing left to do and does not defeat the copy.
            if ($operation instanceof Orient) {
                continue;
            }

            if (!$operation instanceof Solvable || !$operation->solve($this->input->size)->isNoop($this->input->size)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The available drivers, most capable first.
     *
     * Imagick leads when it is there: it reads strictly more formats, it is the
     * only one of the two that understands an ICC profile, and its
     * shrink-on-load halves the decode that dominates a large JPEG. GD is not a
     * fallback in any pejorative sense; it is the tier-one driver everywhere
     * Imagick is absent, which is most places.
     *
     * @return list<DriverInterface>
     */
    public static function installed(): array
    {
        $drivers = [];

        if (ImagickDriver::isAvailable()) {
            $drivers[] = new ImagickDriver();
        }

        if (GdDriver::isAvailable()) {
            $drivers[] = new GdDriver();
        }

        return $drivers;
    }

    /**
     * Every driver this build knows how to name, installed or not, for doctor.
     *
     * @return list<DriverInterface>
     */
    public static function known(): array
    {
        return [new ImagickDriver(), new GdDriver()];
    }

    /**
     * Asks one driver every question, and returns either why it said no or the
     * list of things it will only approximate.
     *
     * @param list<Output> $specs
     *
     * @return list<string>|string a refusal, or the degradations it accepts
     */
    private static function interview(DriverInterface $driver, Metadata $input, array $specs): array|string
    {
        $decode = $driver->canDecode($input->format);

        if (Support::No === $decode) {
            // Build the full capability table only on the rejection path. Probing
            // every writable format is expensive and may load external delegates.
            return $driver->capabilities()->isAvailable()
                ? \sprintf('cannot read %s', $input->format->value)
                : 'not installed';
        }

        $degradations = Support::Approximate === $decode
            ? [\sprintf('%s reads %s with losses', $driver->name(), $input->format->value)]
            : [];

        foreach ($specs as $spec) {
            $encode = $driver->canEncode($spec->encoding->resolve($input->format));

            if (Support::No === $encode) {
                return \sprintf('cannot write %s', $spec->encoding->formatOr($input->format)->value);
            }

            if (Support::Approximate === $encode) {
                $degradations[] = \sprintf(
                    '%s writes %s approximately',
                    $driver->name(),
                    $spec->encoding->formatOr($input->format)->value,
                );
            }

            foreach ($spec->transform->operations as $operation) {
                $support = $driver->supports($operation);

                if (Support::No === $support) {
                    return \sprintf('cannot apply %s', $operation::class);
                }

                // The pipeline reports the concrete degradation at runtime. The
                // conformance suite requires a note for approximate operations.
            }
        }

        return array_values(array_unique($degradations));
    }

    /**
     * @param list<Output> $specs
     */
    private static function describe(Metadata $input, array $specs): string
    {
        $targets = array_unique(array_map(
            static fn(Output $spec): string => $spec->encoding->formatOr($input->format)->value,
            $specs,
        ));

        return \sprintf('turn %s into %s', $input->format->value, implode(' and ', $targets));
    }

    /**
     * Chooses installation guidance from the driver refusal reasons.
     *
     * @param list<Output> $specs
     * @param list<string> $refusals
     */
    private static function remedy(Metadata $input, array $specs, array $refusals): string
    {
        $said = implode(' ', $refusals);

        if ($input->format->isVector()) {
            return 'nothing in this package rasterises vectors';
        }

        // Nothing at all is installed, so the answer is the obvious one.
        if (str_contains($said, 'not installed') && [] === self::installed()) {
            // @codeCoverageIgnoreStart
            return 'install ext-gd or ext-imagick';
            // @codeCoverageIgnoreEnd
        }

        if (str_contains($said, 'cannot apply')) {
            return str_contains($said, 'IccConvert')
                ? 'GD has no concept of a colour profile; install ext-imagick built against lcms, or drop the icc step'
                : 'no installed driver implements that operation; install ext-imagick or remove the unsupported step';
        }

        $exotic = [Format::Heic, Format::Jxl, Format::Tiff];
        $formats = [$input->format];

        foreach ($specs as $spec) {
            $formats[] = $spec->encoding->formatOr($input->format);
        }

        foreach ($formats as $format) {
            if (\in_array($format, $exotic, true)) {
                return \sprintf(
                    'install ext-imagick built against %s',
                    Format::Jxl === $format ? 'libjxl' : 'libheif and libtiff',
                );
            }
        }

        return 'install ext-gd or ext-imagick';
    }
}
