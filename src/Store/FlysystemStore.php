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
use Alto\Image\Result;
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

    public function ensureOne(Image $image): Result
    {
        return $this->ensure(ImageSet::of($image))[0];
    }

    public function ensureMany(ImageSet $images): array
    {
        return $this->ensure($images);
    }

    /**
     * @return list<Result>
     */
    private function ensure(ImageSet $images): array
    {
        $singles = $images->images();
        $results = [];
        $missing = [];

        foreach ($singles as $offset => $one) {
            $path = $this->path($one);

            if ($this->exists($path)) {
                $results[$offset] = $this->existing($one, $path);

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
     * @return array<int, Result>
     */
    private function generate(ImageSet $images, array $missing): array
    {
        if ([] === $missing) {
            return [];
        }

        $offsets = array_keys($missing);
        $subset = $images->select(...$offsets);
        $work = static fn(): array => $subset->render();
        $key = 'alto-image-' . hash('xxh128', implode("\0", $missing));
        $rendered = null === $this->criticalSection ? $work() : ($this->criticalSection)($key, $work);

        if (!\is_array($rendered) || \count($rendered) !== \count($missing)) {
            throw new StoreException(\sprintf(
                'The critical section returned %s instead of the %d results the closure it was given produces.',
                get_debug_type($rendered),
                \count($missing),
            ));
        }

        $results = [];

        foreach (array_values($rendered) as $at => $result) {
            if (!$result instanceof Result) {
                throw new StoreException('The critical section returned something that is not a list of Results.');
            }

            $path = $missing[$offsets[$at]];

            try {
                $this->filesystem->write($path, $result->bytes, [
                    'mimetype' => $result->metadata->format->mime(),
                    'visibility' => 'public',
                ]);
            } catch (FilesystemException $error) {
                throw new StoreException(\sprintf('Could not write "%s": %s', $path, $error->getMessage()), 0, $error);
            }

            $results[$offsets[$at]] = $result->withPath($path);
        }

        return $results;
    }

    private function existing(Image $image, string $path): Result
    {
        try {
            $bytes = $this->filesystem->read($path);
        } catch (FilesystemException $error) {
            throw new StoreException(\sprintf('Could not read "%s": %s', $path, $error->getMessage()), 0, $error);
        }

        return new Result(
            $image->metadata()->with(bytes: \strlen($bytes)),
            $bytes,
            'store',
            [],
            0.0,
            $path,
            true,
        );
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
