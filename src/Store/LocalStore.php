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

use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Exception\StoreException;
use Alto\Image\Image;
use Alto\Image\ImageSet;
use Alto\Image\Internal\AtomicWriter;

/**
 * A signature-keyed local derivative store with atomic writes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LocalStore implements StoreInterface
{
    /**
     * How many hexadecimal digits of the signature name the shard directory.
     *
     * One gives sixteen directories, which keeps a large store off the pathological
     * end of every filesystem's directory-size behaviour without making a path
     * unreadable.
     */
    private const int SHARD = 1;

    private string $root;

    /**
     * @param \Closure(string, \Closure(): mixed): mixed|null $criticalSection an optional lock, called with a key and the work
     */
    public function __construct(
        string $root,
        private ?\Closure $criticalSection = null,
    ) {
        $root = rtrim($root, '/');

        if ('' === $root) {
            throw new InvalidArgumentException('A local store needs a directory other than the filesystem root.');
        }

        $this->root = $root;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(Image $image): string
    {
        $signature = $image->signature();

        return \sprintf(
            '%s/%s/%s-%s.%s',
            $this->root,
            substr($signature, 0, self::SHARD),
            $this->slug($image->name()),
            $signature,
            $image->metadata()->format->extension(),
        );
    }

    public function has(Image $image): bool
    {
        return is_file($this->path($image));
    }

    public function ensureOne(Image $image): string
    {
        return $this->ensure(ImageSet::of($image))[0];
    }

    public function ensureMany(ImageSet $images): array
    {
        return $this->ensure($images);
    }

    /**
     * @return list<string>
     */
    private function ensure(ImageSet $images): array
    {
        $work = fn(): array => $this->ensureUnlocked($images);
        $first = $images->images()[0];
        $first->sourceMetadata();
        $key = 'alto-image-' . $first->source()->signature();
        $paths = null === $this->criticalSection ? $work() : ($this->criticalSection)($key, $work);

        if (!\is_array($paths) || !array_is_list($paths) || \count($paths) !== $images->count()) {
            throw new StoreException('The critical section must return the list of paths produced by its closure.');
        }

        foreach ($paths as $path) {
            if (!\is_string($path)) {
                throw new StoreException('The critical section must return a list of string paths.');
            }
        }

        return $paths;
    }

    /**
     * Rechecks cached outputs and publishes missing files while holding the lock.
     *
     * @return list<string>
     */
    private function ensureUnlocked(ImageSet $images): array
    {
        $singles = $images->images();
        $results = [];
        $missing = [];

        foreach ($singles as $offset => $one) {
            $path = $this->path($one);

            if (is_file($path)) {
                $results[$offset] = $path;

                continue;
            }

            $missing[$offset] = $path;
        }

        foreach ($this->generate($images, $missing) as $offset => $result) {
            $results[$offset] = $result;
        }

        ksort($results);

        return array_values($results);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        if (!is_dir($this->root)) {
            return 0;
        }

        $removed = 0;
        $cutoff = $before->getTimestamp();

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            if ($file->isDir()) {
                // An empty shard is swept with the files it held, and a shard that
                // still holds something silently refuses to go.
                @rmdir($file->getPathname());

                continue;
            }

            // Prefer access time when available and fall back to modification time.
            $touched = max($file->getATime(), $file->getMTime());

            if ($touched < $cutoff && @unlink($file->getPathname())) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Renders the missing outputs of one source in a single call.
     *
     * Re-negotiates only missing outputs so cached rungs are not rendered again.
     *
     * @param array<int, string> $missing spec offset to destination path
     *
     * @return array<int, string>
     */
    private function generate(ImageSet $images, array $missing): array
    {
        if ([] === $missing) {
            return [];
        }

        $offsets = array_keys($missing);
        $subset = $images->select(...$offsets);
        $rendered = $subset->bytes();
        $results = [];

        foreach (array_values($rendered) as $at => $result) {
            $path = $missing[$offsets[$at]];

            AtomicWriter::write($path, $result);
            $results[$offsets[$at]] = $path;
        }

        return $results;
    }

    /**
     * A filename component that is safe on every filesystem and still readable.
     */
    private function slug(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        $slug = trim($slug, '-');

        return '' === $slug ? 'image' : substr($slug, 0, 60);
    }
}
