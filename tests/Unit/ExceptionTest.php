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

use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Exception\DriverException;
use Alto\Image\Exception\LimitExceededException;
use Alto\Image\Exception\SourceNotFoundException;
use Alto\Image\Exception\StoreException;
use Alto\Image\Exception\UnmeasurableException;
use Alto\Image\Exception\UnsupportedOperationException;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorruptImageException::class)]
#[CoversClass(DriverException::class)]
#[CoversClass(LimitExceededException::class)]
#[CoversClass(SourceNotFoundException::class)]
#[CoversClass(StoreException::class)]
#[CoversClass(UnmeasurableException::class)]
#[CoversClass(UnsupportedOperationException::class)]
final class ExceptionTest extends TestCase
{
    public function testCorruptInputMessagesNameTheSourceAndRecoveryPolicy(): void
    {
        self::assertStringContainsString('upload.jpg', CorruptImageException::truncated('upload.jpg', 'jpeg', 'truncated')->getMessage());
        self::assertStringContainsString('FailOn::None', CorruptImageException::truncated('upload.jpg', 'jpeg', 'truncated')->getMessage());
        self::assertStringContainsString('not an image', CorruptImageException::unreadableHeader('upload.bin')->getMessage());
    }

    public function testEveryLimitMessageNamesItsValueAndPolicy(): void
    {
        self::assertStringContainsString('12,000,000', LimitExceededException::pixels(new Size(4000, 3000), 1_000_000, 'photo')->getMessage());
        self::assertStringContainsString('maxDimension', LimitExceededException::dimension(new Size(1, 9000), 8000, 'photo')->getMessage());
        self::assertStringContainsString('20 frames', LimitExceededException::frames(20, 10, 'animation')->getMessage());
        self::assertStringContainsString('2,048 bytes', LimitExceededException::bytes(2048, 1024, 'upload')->getMessage());
    }

    public function testOperationalMessagesNameTheFailedActionAndRemedy(): void
    {
        self::assertSame('gd failed while encoding. delegate missing', DriverException::failed('gd', 'encoding', 'delegate missing')->getMessage());
        self::assertStringContainsString('Check the directory', StoreException::notWritable('/cache/a.webp', 'write')->getMessage());
        self::assertStringContainsString('cover=800x450', UnmeasurableException::trimmed('size()', 'trim')->getMessage());
        self::assertStringContainsString('raw handle', UnmeasurableException::escaped('metadata()')->getMessage());

        $unsupported = UnsupportedOperationException::noDriverFor('write jxl', ['gd: cannot write jxl', 'imagick: cannot write jxl'], 'install libjxl');
        self::assertStringContainsString('gd: cannot write jxl', $unsupported->getMessage());
        self::assertStringContainsString('Try: install libjxl', $unsupported->getMessage());
    }

    public function testMissingAndDirectorySourcesAreDistinguished(): void
    {
        self::assertStringContainsString('No such file', SourceNotFoundException::at('/path/that/does/not/exist')->getMessage());
        self::assertStringContainsString('is a directory', SourceNotFoundException::at(sys_get_temp_dir())->getMessage());
        self::assertStringContainsString('not readable', SourceNotFoundException::at(__FILE__)->getMessage());
    }
}
