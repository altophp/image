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

namespace Alto\Image\Tests\Exception;

use Alto\Image\Exception\ImageExceptionInterface;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InvalidArgumentException::class)]
final class InvalidArgumentExceptionTest extends SourceClassTestCase
{
    protected const string SUBJECT = InvalidArgumentException::class;

    public function testBelongsToTheNativeAndPackageExceptionHierarchies(): void
    {
        $exception = new InvalidArgumentException('Invalid image option.');

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertInstanceOf(ImageExceptionInterface::class, $exception);
    }
}
