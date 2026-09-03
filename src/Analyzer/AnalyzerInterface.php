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

namespace Alto\Image\Analyzer;

/**
 * Reduces a bounded RGBA raster to an application value.
 *
 * @template TResult
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface AnalyzerInterface
{
    /**
     * @return TResult
     */
    public function analyze(Raster $raster): mixed;
}
