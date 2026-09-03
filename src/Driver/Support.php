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

/**
 * Describes whether a driver supports work exactly, approximately, or not at all.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum Support
{
    /**
     * Performed as specified.
     */
    case Exact;

    /**
     * Performed, but not as specified. The Result records a degradation.
     */
    case Approximate;

    /**
     * Not performed at all.
     */
    case No;

    public function isPossible(): bool
    {
        return self::No !== $this;
    }

    /**
     * Whether this answer is at least as good as another.
     *
     * A driver's capability table is the worst case per operation, because a
     * table has one row per class and a class covers many instances: GD rotates
     * a quarter turn exactly and any other angle approximately, and one row
     * cannot say both. So the table is a floor, and this is how the conformance
     * kit checks that an instance never comes in under it.
     */
    public function isAtLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::No => 0,
            self::Approximate => 1,
            self::Exact => 2,
        };
    }

    /**
     * The weakest of several answers, because a chain is as capable as its worst link.
     */
    public function and(self $other): self
    {
        return match (true) {
            self::No === $this || self::No === $other => self::No,
            self::Approximate === $this || self::Approximate === $other => self::Approximate,
            default => self::Exact,
        };
    }
}
