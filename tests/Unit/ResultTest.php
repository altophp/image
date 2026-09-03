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

namespace Alto\Image\Tests\Unit;

use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\Result;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Result::class)]
final class ResultTest extends TestCase
{
    public function testItExposesWhatWasActuallyProduced(): void
    {
        $result = new Result(new Metadata(new Size(320, 180), Format::Webp), 'bytes', 'fake', ['blur approximated'], 0.012, copied: true);

        self::assertSame('320x180', (string) $result->size());
        self::assertSame(Format::Webp, $result->format());
        self::assertSame(5, $result->length());
        self::assertFalse($result->isExact());
        self::assertSame('data:image/webp;base64,Ynl0ZXM=', $result->dataUri());
        self::assertSame('/tmp/output.webp', $result->withPath('/tmp/output.webp')->path);
        self::assertStringContainsString('5 B in 12.0 ms (copied) [blur approximated]', (string) $result);
    }

    public function testItFormatsKilobytesAndMegabytes(): void
    {
        $metadata = new Metadata(new Size(1, 1), Format::Png);

        self::assertStringContainsString('2.0 KB', (string) new Result($metadata, str_repeat('x', 2048)));
        self::assertStringContainsString('2.0 MB', (string) new Result($metadata, str_repeat('x', 2 * 1024 * 1024)));
        self::assertTrue((new Result($metadata, 'x'))->isExact());
    }

    public function testAResultCannotContradictItsOwnBytesOrTime(): void
    {
        try {
            new Result(new Metadata(new Size(1, 1), Format::Png, bytes: 2), 'one');
            self::fail('A result contradicted its byte count.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        new Result(new Metadata(new Size(1, 1), Format::Png), 'one', duration: -1.0);
    }
}
