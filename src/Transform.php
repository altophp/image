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

use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Exception\UnmeasurableException;
use Alto\Image\Operation\Adjust;
use Alto\Image\Operation\Blur;
use Alto\Image\Operation\Crop;
use Alto\Image\Operation\Escape;
use Alto\Image\Operation\Extend;
use Alto\Image\Operation\Flatten;
use Alto\Image\Operation\Flip;
use Alto\Image\Operation\Grayscale;
use Alto\Image\Operation\IccConvert;
use Alto\Image\Operation\Invert;
use Alto\Image\Operation\OperationInterface;
use Alto\Image\Operation\Orient;
use Alto\Image\Operation\Overlay;
use Alto\Image\Operation\Pixelate;
use Alto\Image\Operation\PortableOperationInterface;
use Alto\Image\Operation\Resize;
use Alto\Image\Operation\Rotate;
use Alto\Image\Operation\Sharpen;
use Alto\Image\Operation\Tint;
use Alto\Image\Operation\Trim;

/**
 * An immutable ordered list of operations with a portable string form.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Transform implements \Stringable, \Countable
{
    /**
     * The argument key that carries the step name.
     *
     * The grammar cannot produce this key from positional or named arguments.
     * Resize uses it to distinguish the five operation names mapped to that class.
     */
    public const string NAME = '@';

    /**
     * @param list<OperationInterface> $operations
     */
    private function __construct(public array $operations = []) {}

    public static function new(): self
    {
        return new self();
    }

    public function with(OperationInterface ...$operations): self
    {
        return new self([...$this->operations, ...array_values($operations)]);
    }

    /**
     * Maps transform names to their operation classes.
     *
     * Five names map to Resize so strings can use forms such as `cover=800x450`.
     *
     * @return array<string, class-string<PortableOperationInterface>>
     */
    public static function defaults(): array
    {
        return [
            'cover' => Resize::class,
            'contain' => Resize::class,
            'fill' => Resize::class,
            'inside' => Resize::class,
            'outside' => Resize::class,
            'crop' => Crop::class,
            'extend' => Extend::class,
            'trim' => Trim::class,
            'rotate' => Rotate::class,
            'flip' => Flip::class,
            'orient' => Orient::class,
            'flatten' => Flatten::class,
            'overlay' => Overlay::class,
            'blur' => Blur::class,
            'sharpen' => Sharpen::class,
            'adjust' => Adjust::class,
            'grayscale' => Grayscale::class,
            'invert' => Invert::class,
            'pixelate' => Pixelate::class,
            'tint' => Tint::class,
            'icc' => IccConvert::class,
        ];
    }

    /**
     * @param list<string>|null                                      $only       operation names accepted from this string
     * @param array<string, class-string<PortableOperationInterface>> $extensions additional or replacement names
     *
     * @throws InvalidArgumentException on an unknown name or a malformed argument
     */
    public static function parse(string $source, ?array $only = null, array $extensions = []): self
    {
        $known = [...self::defaults(), ...$extensions];

        if (null !== $only) {
            $allowed = [];

            foreach ($only as $name) {
                $allowed[$name] = $known[$name] ?? throw new InvalidArgumentException(\sprintf(
                    'Cannot allow unknown operation "%s". Known: %s.',
                    $name,
                    implode(', ', array_keys($known)),
                ));
            }

            $known = $allowed;
        }

        return self::parseWith($source, $known);
    }

    /**
     * @param array<string, class-string<PortableOperationInterface>> $known
     */
    private static function parseWith(string $source, array $known): self
    {
        $parsed = [];

        foreach (array_filter(array_map(trim(...), explode('|', $source)), static fn(string $s): bool => '' !== $s) as $step) {
            [$name, $arguments] = array_pad(explode('=', $step, 2), 2, '');

            $class = $known[$name] ?? throw new InvalidArgumentException(\sprintf(
                'Unknown operation "%s" in transform "%s". Known: %s.',
                $name,
                $source,
                implode(', ', array_keys($known)),
            ));

            $parsed[] = $class::parse([self::NAME => $name] + self::arguments($arguments, $step));
        }

        return new self($parsed);
    }

    /**
     * @return array<array-key, string>
     */
    private static function arguments(string $arguments, string $step): array
    {
        $parsed = [];
        $position = 0;

        foreach (explode(',', $arguments) as $pair) {
            if ('' === $pair) {
                ++$position;

                continue;
            }

            if (!str_contains($pair, ':')) {
                $parsed[(string) $position++] = $pair;

                continue;
            }

            [$key, $value] = explode(':', $pair, 2);

            if (1 !== preg_match('/^[a-z][a-z0-9]*$/', $key)) {
                throw new InvalidArgumentException(\sprintf(
                    'Argument name "%s" in "%s" is not lowercase alphanumeric. The grammar reserves everything else.',
                    $key,
                    $step,
                ));
            }

            $parsed[$key] = $value;
        }

        return $parsed;
    }

    public function __toString(): string
    {
        return implode('|', array_map(
            static function (OperationInterface $operation): string {
                if (!$operation instanceof PortableOperationInterface) {
                    $label = $operation instanceof Escape ? $operation->label : $operation::class;

                    throw new UnmeasurableException(\sprintf(
                        "Cannot serialise the \"%s\" operation.\n"
                        . '  It is executable but has no portable transform-string representation.',
                        $label,
                    ));
                }

                return (string) $operation;
            },
            $this->operations,
        ));
    }

    /**
     * The cache identity, including dependencies that live outside the source.
     */
    public function signature(): string
    {
        return implode('|', array_map(
            static fn(OperationInterface $operation): string => match (true) {
                $operation instanceof Escape => $operation->signature(),
                $operation instanceof Overlay => (string) $operation . '#' . $operation->dependencySignature(),
                $operation instanceof IccConvert => (string) $operation . '#' . $operation->dependencySignature(),
                $operation instanceof PortableOperationInterface => (string) $operation,
                default => throw new InvalidArgumentException(\sprintf('%s has no cache identity.', $operation::class)),
            },
            $this->operations,
        ));
    }

    public function count(): int
    {
        return \count($this->operations);
    }

    public function isEmpty(): bool
    {
        return [] === $this->operations;
    }

    /**
     * Runs every projection in order, so an Image knows its output before any
     * decoder has opened the file.
     */
    public function project(Metadata $input): Metadata
    {
        foreach ($this->operations as $operation) {
            if (!$operation instanceof PortableOperationInterface) {
                $label = $operation instanceof Escape ? $operation->label : $operation::class;

                throw new UnmeasurableException(\sprintf(
                    "Cannot project through the \"%s\" operation.\n"
                    . '  Native code has no header-only output contract.',
                    $label,
                ));
            }

            $input = $operation->project($input);
        }

        return $input;
    }

    /**
     * Projects operations until the first non-portable step.
     */
    public function estimate(Metadata $input): Metadata
    {
        foreach ($this->operations as $operation) {
            if (!$operation instanceof PortableOperationInterface) {
                return $input;
            }

            $input = $operation->project($input);
        }

        return $input;
    }

    /**
     * Whether the transform determines the exact output size.
     *
     * Measurability is a property of the chain, not of any one step. A Trim
     * removes a border only the pixels know about, and a later Resize that pins
     * both axes down with no scale clamp makes the answer knowable again, which
     * is the normal shape of `trim|cover=800x450,s:both`. An Escape is the one
     * step nothing can recover from.
     *
     * Third-party operations are taken at their word: an implementation that
     * projects a size it does not produce fails the conformance kit, which is
     * where that lie is supposed to surface.
     */
    public function isMeasurable(): bool
    {
        $measurable = true;

        foreach ($this->operations as $operation) {
            if (!$operation instanceof PortableOperationInterface) {
                return false;
            }

            if ($operation instanceof Trim) {
                $measurable = false;

                continue;
            }

            if ($operation instanceof Resize && $operation->fixesOutput()) {
                $measurable = true;
            }
        }

        return $measurable;
    }

    /**
     * Rewrites the last Resize wherever it sits, or appends one when there is none.
     *
     * This is what `widths()` and `heights()` walk, and it has to reach past
     * later operations: `->cover(1280, 720)->sharpen()->widths(640, 960)` is one
     * resize at two sizes, not two resizes.
     *
     * @param \Closure(Resize|null): Resize $mutate
     */
    public function withResize(\Closure $mutate): self
    {
        $operations = $this->operations;

        for ($i = \count($operations) - 1; $i >= 0; --$i) {
            if ($operations[$i] instanceof Resize) {
                $operations[$i] = $mutate($operations[$i]);

                return new self(array_values($operations));
            }
        }

        return new self([...$operations, $mutate(null)]);
    }

    /**
     * Restates the shape, rewriting the trailing Resize or appending a new one.
     *
     * The difference from withResize() is where it will reach. A shaping verb
     * restates the box when the box is the last thing said, and adds a step when
     * something has happened since:
     *
     *     ->fit(400, 400)->cover(300, 200)          one resize, restated
     *     ->fit(400, 400)->trim()->cover(300, 200)  three steps, in that order
     *
     * Reaching past the trim in the second case would silently reorder what the
     * caller wrote, and a transform is an ordered list precisely because the
     * order is a decision.
     *
     * @param \Closure(Resize|null): Resize $mutate
     */
    public function reshape(\Closure $mutate): self
    {
        $last = $this->operations[\count($this->operations) - 1] ?? null;

        if ($last instanceof Resize) {
            $operations = $this->operations;
            $operations[\count($operations) - 1] = $mutate($last);

            return new self(array_values($operations));
        }

        return new self([...$this->operations, $mutate(null)]);
    }

    /**
     * The last resize in the operation list.
     */
    public function resize(): ?Resize
    {
        for ($i = \count($this->operations) - 1; $i >= 0; --$i) {
            $operation = $this->operations[$i];

            if ($operation instanceof Resize) {
                return $operation;
            }
        }

        return null;
    }

    public function contains(string $class): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
