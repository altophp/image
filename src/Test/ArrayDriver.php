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

namespace Alto\Image\Test;

use Alto\Image\Driver\Capabilities;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Output;
use Alto\Image\Driver\Plan;
use Alto\Image\Driver\Support;
use Alto\Image\Format;
use Alto\Image\Operation\Escape;
use Alto\Image\Operation\OperationInterface;
use Alto\Image\Result;

/**
 * A recording test driver that returns projected metadata without decoding.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ArrayDriver implements DriverInterface
{
    /**
     * @var list<array{source: string, spec: string, output: string}>
     */
    private array $calls = [];

    private int $batches = 0;

    /**
     * @param array<string, string> $canned bytes to return, keyed by output signature
     */
    public function __construct(private readonly array $canned = []) {}

    public function name(): string
    {
        return 'array';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            $this->name(),
            'fake',
            Format::cases(),
            array_values(array_filter(Format::cases(), static fn(Format $f): bool => !$f->isVector())),
            [OperationInterface::class => Support::Exact],
            ['a fake: it decodes nothing and the bytes it returns are not a picture'],
        );
    }

    public function supports(OperationInterface $operation): Support
    {
        // An Escape runs arbitrary code against a native handle this driver does
        // not have, so it is the one thing the fake genuinely cannot pretend at.
        return $operation instanceof Escape ? Support::No : Support::Exact;
    }

    public function canDecode(Format $format): Support
    {
        return $format->isVector() ? Support::Approximate : Support::Exact;
    }

    public function canEncode(Encoding $encoding): Support
    {
        return null !== $encoding->format && $encoding->format->isVector() ? Support::No : Support::Exact;
    }

    public function process(Plan $plan): array
    {
        ++$this->batches;
        $results = [];

        foreach ($plan->requests as $index => $spec) {
            $output = $plan->outputs[$index];
            $bytes = $this->canned[$spec->signature()] ?? $this->marker($plan, $spec, $index);

            $this->calls[] = [
                'source' => $plan->source->origin(),
                'spec' => (string) $spec,
                'output' => (string) $output->size,
            ];

            $results[] = new Result(
                $output->with(bytes: \strlen($bytes)),
                $bytes,
                $this->name(),
                $plan->degradations,
                0.0,
            );
        }

        return $results;
    }

    /**
     * What this driver was asked to produce, in the order it was asked.
     *
     * @return list<array{source: string, spec: string, output: string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<string>
     */
    public function outputs(): array
    {
        return array_column($this->calls, 'output');
    }

    public function batches(): int
    {
        return $this->batches;
    }

    public function forget(): void
    {
        $this->calls = [];
        $this->batches = 0;
    }

    private function marker(Plan $plan, Output $spec, int $index): string
    {
        return \sprintf(
            "alto:fake\n%s\n%s\n%s\n",
            $plan->source->origin(),
            $spec,
            $plan->outputs[$index]->size,
        );
    }
}
