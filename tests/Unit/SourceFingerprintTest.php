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

use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Source::class)]
final class SourceFingerprintTest extends TestCase
{
    public function testAFileFingerprintCallbackRunsOnlyOncePerSource(): void
    {
        $calls = 0;
        $source = Source::file('/virtual/photo.png', static function (string $path) use (&$calls): string {
            ++$calls;

            return hash('sha256', $path);
        });

        self::assertSame($source->signature(), $source->signature());
        self::assertSame(1, $calls);
    }
}
