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

namespace Alto\Image\Tests\Test;

use Alto\Image\Format;
use Alto\Image\Source;
use Alto\Image\Tests\Support\PngWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PngWriterTest extends TestCase
{
    public function testItWritesASinglePixelInterlacedPng(): void
    {
        $metadata = Source::bytes(PngWriter::interlaced(1, 1))->metadata();

        self::assertSame(Format::Png, $metadata->format);
        self::assertSame('1x1', (string) $metadata->size);
    }
}
