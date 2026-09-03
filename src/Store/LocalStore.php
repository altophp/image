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
use Alto\Image\Result;

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

            if (is_file($path)) {
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

            // Another process may have finished the same derivative in the
            // meantime. Writing anyway is harmless: the rename is atomic and both
            // processes produced the same bytes from the same signature.
            AtomicWriter::write($path, $result->bytes);
            $results[$offsets[$at]] = $result->withPath($path);
        }

        return $results;
    }

    /**
     * What to report for a derivative that was already there.
     */
    private function existing(Image $image, string $path): Result
    {
        $bytes = @file_get_contents($path);

        if (false === $bytes) {
            // @codeCoverageIgnoreStart
            throw StoreException::notWritable($path, 'read the derivative at');
            // @codeCoverageIgnoreEnd
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
