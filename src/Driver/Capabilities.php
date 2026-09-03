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

use Alto\Image\Format;
use Alto\Image\Operation\OperationInterface;

/**
 * A driver's formats, operations, availability, and diagnostic notes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Capabilities
{
    /**
     * @param list<Format>                                     $reads
     * @param list<Format>                                     $writes
     * @param array<class-string<OperationInterface>, Support> $operations an absent class means Support::No
     * @param list<string>                                     $notes      what doctor should warn about
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $reads,
        public array $writes,
        public array $operations = [],
        public array $notes = [],
    ) {}

    /**
     * The capabilities of a driver whose extension is not installed.
     */
    public static function missing(string $name): self
    {
        return new self($name, '', [], [], [], ['not installed']);
    }

    public function isAvailable(): bool
    {
        return [] !== $this->reads;
    }

    public function canRead(Format $format): bool
    {
        return \in_array($format, $this->reads, true);
    }

    public function canWrite(Format $format): bool
    {
        return \in_array($format, $this->writes, true);
    }

    public function supports(OperationInterface $operation): Support
    {
        if (isset($this->operations[$operation::class])) {
            return $this->operations[$operation::class];
        }

        foreach ($this->operations as $class => $support) {
            if ($operation instanceof $class) {
                return $support;
            }
        }

        return Support::No;
    }

    /**
     * @return list<string>
     */
    public function readNames(): array
    {
        return array_map(static fn(Format $f): string => $f->value, $this->reads);
    }

    /**
     * @return list<string>
     */
    public function writeNames(): array
    {
        return array_map(static fn(Format $f): string => $f->value, $this->writes);
    }
}
