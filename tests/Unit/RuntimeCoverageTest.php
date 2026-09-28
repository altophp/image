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

use Alto\Image\Anchor;
use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Fit;
use Alto\Image\Operation\Resize;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Resize::class)]
#[CoversClass(Source::class)]
final class RuntimeCoverageTest extends TestCase
{
    public function testAStreamHeadBuffersTheWholeSourceForLaterReaders(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        self::assertSame(18, fwrite($stream, 'header-and-payload'));
        $source = Source::stream($stream);

        self::assertSame('header', $source->head(6));
        fclose($stream);

        self::assertSame('header-and-payload', $source->contents());
    }

    public function testAnUnknownSourceExactlyOneProbeLongIsRejected(): void
    {
        $source = Source::bytes(str_repeat('x', 4096), 'one-probe');

        $this->expectException(CorruptImageException::class);
        $this->expectExceptionMessage('one-probe (in memory)');

        $source->metadata();
    }

    public function testChangingResizeOptionsPreservesASingleRequestedAxis(): void
    {
        $fromWidth = (new Resize(640))->with(gravity: Anchor::Top);
        $fromHeight = (new Resize(height: 360))->with(fit: Fit::Outside);

        self::assertSame(640, $fromWidth->width);
        self::assertNull($fromWidth->height);
        self::assertSame('inside=640x,g:top', (string) $fromWidth);
        self::assertNull($fromHeight->width);
        self::assertSame(360, $fromHeight->height);
        self::assertSame('outside=x360', (string) $fromHeight);
    }
}
