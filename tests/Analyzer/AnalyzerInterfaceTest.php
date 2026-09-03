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

namespace Alto\Image\Tests\Analyzer;

use Alto\Image\Analyzer\AnalyzerInterface;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class AnalyzerInterfaceTest extends SourceClassTestCase
{
    protected const string SUBJECT = AnalyzerInterface::class;
}
