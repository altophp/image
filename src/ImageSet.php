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

use Alto\Image\Driver\Output;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Internal\AbstractImage;
use Alto\Image\Store\LocalStore;
use Alto\Image\Store\StoreInterface;

/**
 * Several requested outputs derived from one source and rendered with one decode.
 *
 * @implements \IteratorAggregate<int, Image>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ImageSet extends AbstractImage implements \Countable, \IteratorAggregate, \Stringable
{
    public static function of(Image $first, Image ...$others): self
    {
        $specs = [$first->specs[0]];

        foreach ($others as $other) {
            if (!$first->sameSource($other)) {
                throw new InvalidArgumentException('An ImageSet can only combine outputs derived from the same source.');
            }

            if ($first->driver !== $other->driver || $first->limits != $other->limits) {
                throw new InvalidArgumentException('An ImageSet must use the same driver and limits. Configure the source before deriving its outputs.');
            }

            $specs[] = $other->specs[0];
        }

        return new self($first->source, $specs, $first->limits, $first->driver);
    }

    public function count(): int
    {
        return \count($this->specs);
    }

    /**
     * @return list<Image>
     */
    public function images(): array
    {
        return array_map(
            fn(Output $spec): Image => new Image($this->source, [$spec], $this->limits, $this->driver),
            $this->specs,
        );
    }

    /**
     * @return \Traversable<int, Image>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->images();
    }

    /**
     * Selects outputs by their zero-based indexes, preserving the requested order.
     */
    public function select(int ...$indexes): self
    {
        if ([] === $indexes) {
            throw new InvalidArgumentException('Selecting images needs at least one index.');
        }

        $specs = [];

        foreach ($indexes as $index) {
            if (!isset($this->specs[$index])) {
                throw new InvalidArgumentException(\sprintf('Image index %d does not exist.', $index));
            }

            $specs[] = $this->specs[$index];
        }

        return $this->withSpecs($specs);
    }

    /**
     * @return list<Result>
     */
    public function render(): array
    {
        $plan = $this->plan();

        return $plan->driver->process($plan);
    }

    /**
     * @return list<Result>
     */
    public function store(string|StoreInterface $store): array
    {
        $store = \is_string($store) ? new LocalStore($store) : $store;

        return $store->ensureMany($this);
    }

    public function __toString(): string
    {
        return \sprintf('%d images of %s', $this->count(), $this->source->origin());
    }

    protected function recreate(array $specs, ?Limits $limits, ?\Alto\Image\Driver\DriverInterface $driver): static
    {
        return new self($this->source, $specs, $limits, $driver);
    }
}
