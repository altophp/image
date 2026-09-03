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

namespace Alto\Image\Tests;

use Alto\Image\Fit;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Fit::class)]
final class FitTest extends SourceClassTestCase
{
    protected const string SUBJECT = Fit::class;

    public function testExactFitsAreDistinguishedFromBounds(): void
    {
        self::assertTrue(Fit::Cover->isExact());
        self::assertTrue(Fit::Contain->isExact());
        self::assertTrue(Fit::Fill->isExact());
        self::assertFalse(Fit::Inside->isExact());
        self::assertFalse(Fit::Outside->isExact());
    }
}
