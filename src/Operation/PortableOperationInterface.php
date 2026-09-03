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

namespace Alto\Image\Operation;

use Alto\Image\Metadata;

/**
 * An operation that can be measured and represented in a transform string.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface PortableOperationInterface extends OperationInterface, \Stringable
{
    /**
     * What this produces, computed from the header alone. Never decodes.
     */
    public function project(Metadata $input): Metadata;

    /**
     * @param array<array-key, string> $arguments
     */
    public static function parse(array $arguments): static;
}
