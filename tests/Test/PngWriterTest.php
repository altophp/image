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
use Alto\Image\Test\PngWriter;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class PngWriterTest extends SourceClassTestCase
{
    protected const string SUBJECT = PngWriter::class;

    public function testItWritesASinglePixelInterlacedPng(): void
    {
        $metadata = Source::bytes(PngWriter::interlaced(1, 1))->metadata();

        self::assertSame(Format::Png, $metadata->format);
        self::assertSame('1x1', (string) $metadata->size);
    }
}
