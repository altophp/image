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

namespace Alto\Image\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

abstract class SourceClassTestCase extends TestCase
{
    /**
     * @var class-string
     */
    protected const string SUBJECT = self::class;

    #[Test]
    public function declarationMatchesSourceLayout(): void
    {
        $source = new \ReflectionClass(static::SUBJECT);
        $test = new \ReflectionClass(static::class);
        $relativeNamespace = substr($source->getNamespaceName(), strlen('Alto\\Image'));
        $relativePath = str_replace('\\', '/', $relativeNamespace);
        $expectedFile = dirname(__DIR__, 2) . '/src' . $relativePath . '/' . $source->getShortName() . '.php';

        self::assertSame($source->getShortName() . 'Test', $test->getShortName());
        self::assertSame('Alto\\Image\\Tests' . $relativeNamespace, $test->getNamespaceName());
        self::assertSame(realpath($expectedFile), realpath((string) $source->getFileName()));
        self::assertNotFalse($source->getDocComment(), static::SUBJECT . ' needs a class docblock.');
    }
}
