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
use Alto\Image\Result;

/**
 * The contract for probing capabilities and processing negotiated image plans.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface DriverInterface
{
    /**
     * How this driver names itself in an error message and in doctor's output.
     */
    public function name(): string;

    /**
     * Everything this driver can do, for negotiation and doctor's output.
     */
    public function capabilities(): Capabilities;

    /**
     * Whether this driver can perform an operation, and how faithfully.
     */
    public function supports(OperationInterface $operation): Support;

    /**
     * Whether this driver can read a format. Approximate covers the cases where
     * it can read the container but loses something: a frame, a profile, a bit depth.
     */
    public function canDecode(Format $format): Support;

    /**
     * Whether this driver can write an encoding as specified.
     */
    public function canEncode(Encoding $encoding): Support;

    /**
     * Produces every requested output from one source, in the order the Plan lists them.
     *
     * A driver can share one decode across every output in the Plan.
     *
     * @return list<Result> one per requested output, same order
     */
    public function process(Plan $plan): array;
}
