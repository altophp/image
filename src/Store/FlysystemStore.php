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

use Alto\Image\Exception\StoreException;
use Alto\Image\Image;
use Alto\Image\ImageSet;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * A signature-keyed derivative store backed by Flysystem.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FlysystemStore implements StoreInterface
{
    private string $prefix;

    /**
     * @param string $prefix a path inside the filesystem, or an empty string for its root
     */
    public function __construct(
        private FilesystemOperator $filesystem,
        string $prefix = '',
        private ?\Closure $criticalSection = null,
    ) {
        $this->prefix = trim($prefix, '/');
    }

    public function path(Image $image): string
    {
        $signature = $image->signature();

        $path = \sprintf(
            '%s/%s-%s.%s',
            substr($signature, 0, 1),
            $this->slug($image->name()),
            $signature,
            $image->metadata()->format->extension(),
        );

        return '' === $this->prefix ? $path : $this->prefix . '/' . $path;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function has(Image $image): bool
    {
        try {
            return $this->filesystem->fileExists($this->path($image));
        } catch (FilesystemException $error) {
            throw new StoreException('Could not ask the filesystem whether a derivative exists: ' . $error->getMessage(), 0, $error);
        }
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

            if ($this->exists($path)) {
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
        $removed = 0;
        $cutoff = $before->getTimestamp();

        try {
            /** @var StorageAttributes $item */
            foreach ($this->filesystem->listContents($this->prefix, true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                // Object stores keep a modification time and no access time, so
                // unlike LocalStore this can only prune by age, not by disuse.
                if (($item->lastModified() ?? 0) < $cutoff) {
                    $this->filesystem->delete($item->path());
                    ++$removed;
                }
            }
        } catch (FilesystemException $error) {
            throw new StoreException('Could not prune the store: ' . $error->getMessage(), 0, $error);
        }

        return $removed;
    }

    /**
     * @param array<int, string> $missing
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

            try {
                $this->filesystem->write($path, $result, [
                    'mimetype' => $subset->images()[$at]->metadata()->format->mime(),
                    'visibility' => 'public',
                ]);
            } catch (FilesystemException $error) {
                throw new StoreException(\sprintf('Could not write "%s": %s', $path, $error->getMessage()), 0, $error);
            }

            $results[$offsets[$at]] = $path;
        }

        return $results;
    }

    private function exists(string $path): bool
    {
        try {
            return $this->filesystem->fileExists($path);
        } catch (FilesystemException $error) {
            throw new StoreException(\sprintf('Could not stat "%s": %s', $path, $error->getMessage()), 0, $error);
        }
    }

    private function slug(string $name): string
    {
        $slug = trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)), '-');

        return '' === $slug ? 'image' : substr($slug, 0, 60);
    }
}
