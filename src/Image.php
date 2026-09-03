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

use Alto\Image\Analyzer\AnalyzerInterface;
use Alto\Image\Analyzer\Raster;
use Alto\Image\Driver\Output;
use Alto\Image\Internal\AbstractImage;
use Alto\Image\Internal\AtomicWriter;
use Alto\Image\Store\LocalStore;
use Alto\Image\Store\StoreInterface;

/**
 * A lazy request for one output derived from one image source.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Image extends AbstractImage implements \Stringable
{
    public static function open(string|Source $source): self
    {
        return new self(Source::of($source), [Output::new()]);
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function size(): Size
    {
        return $this->metadata()->size;
    }

    public function sourceSize(): Size
    {
        return $this->sourceMetadata()->size;
    }

    public function metadata(): Metadata
    {
        $spec = $this->specs[0];
        $this->requireMeasurable('metadata()', $spec);

        return $spec->project($this->source->metadata()->oriented());
    }

    public function sourceMetadata(): Metadata
    {
        return $this->source->metadata()->oriented();
    }

    public function transform(): Transform
    {
        return $this->specs[0]->transform;
    }

    public function signature(): string
    {
        $spec = $this->specs[0];

        return substr(hash('xxh128', implode("\0", [
            $this->source->signature(),
            $spec->signature(),
        ])), 0, 16);
    }

    public function name(): string
    {
        return $this->source->name;
    }

    public function save(string $path): Result
    {
        $result = $this->render();
        AtomicWriter::write($path, $result->bytes);

        return $result->withPath($path);
    }

    public function store(string|StoreInterface $store): Result
    {
        $store = \is_string($store) ? new LocalStore($store) : $store;

        return $store->ensureOne($this);
    }

    public function bytes(): string
    {
        return $this->render()->bytes;
    }

    public function dataUri(): string
    {
        return $this->render()->dataUri();
    }

    public function render(): Result
    {
        $plan = $this->plan();

        return $plan->driver->process($plan)[0];
    }

    /**
     * @template TResult
     *
     * @param AnalyzerInterface<TResult> $analyzer
     *
     * @return TResult
     */
    public function analyze(AnalyzerInterface $analyzer): mixed
    {
        return $analyzer->analyze(Raster::of($this));
    }

    public function __toString(): string
    {
        return $this->source->origin() . ' -> ' . $this->specs[0];
    }

    protected function recreate(array $specs, ?\Alto\Image\Limits $limits, ?\Alto\Image\Driver\DriverInterface $driver): static
    {
        return new self($this->source, $specs, $limits, $driver);
    }
}
