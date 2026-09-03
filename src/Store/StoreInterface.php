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

namespace Alto\Image\Store;

use Alto\Image\Image;
use Alto\Image\ImageSet;
use Alto\Image\Result;

/**
 * The contract for locating, generating, and pruning stored derivatives.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface StoreInterface
{
    /**
     * Where this output lives, computable with nothing on disk.
     *
     * Deterministic from the image's signature, which covers the source identity,
     * the transform and the encoding. That is what lets a template emit a URL
     * without touching the filesystem and without generating anything.
     */
    public function path(Image $image): string;

    public function has(Image $image): bool;

    /**
     * Generates one derivative when it is missing.
     */
    public function ensureOne(Image $image): Result;

    /**
     * Generates the missing images with one decode, preserving their order.
     *
     * @return list<Result>
     */
    public function ensureMany(ImageSet $images): array;

    /**
     * Removes derivatives untouched since a moment. Returns how many went.
     */
    public function prune(\DateTimeImmutable $before): int;
}
