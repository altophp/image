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

use Alto\Image\Size;

/**
 * A geometry operation that resolves to a driver-independent placement.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface Solvable
{
    public function solve(Size $source): Placement;
}
