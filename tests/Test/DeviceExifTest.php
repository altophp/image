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

use Alto\Image\Test\DeviceExif;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class DeviceExifTest extends SourceClassTestCase
{
    protected const string SUBJECT = DeviceExif::class;

    public function testItProvidesTheDocumentedSegment(): void
    {
        $segment = DeviceExif::segment();

        self::assertStringStartsWith("\xFF\xE1", $segment);
        self::assertStringContainsString("Exif\x00\x00", $segment);
        self::assertSame('69cc8c01861d85e6d5ca2a044bb3e0470b174206a8724fbaccd28b44b7dfc70a', hash('sha256', $segment));
    }
}
