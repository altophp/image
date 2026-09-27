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

namespace Alto\Image\Tests\Internal;

use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Internal\Arguments;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Arguments::class)]
final class ArgumentsTest extends SourceClassTestCase
{
    protected const string SUBJECT = Arguments::class;

    public function testItAcceptsValuesThatMatchTheOperationSchema(): void
    {
        Arguments::check(
            ['@' => 'cover', 0 => '800x', 'g' => 'center', 's' => '-2', 'r' => '1.7777777778'],
            [0 => 'box', 'g' => 'string', 's' => 'int', 'r' => 'float'],
        );
        Arguments::check([0 => '800x450'], [0 => 'size']);

        self::addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{array<array-key, string>, array<array-key, string>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'unknown key' => [['@' => 'rotate', 'typo' => '1'], [0 => 'float'], 'Unknown argument "typo" for rotate'];
        yield 'decimal integer' => [[0 => '3.5'], [0 => 'int'], 'Invalid int argument'];
        yield 'overflowing integer' => [[0 => '999999999999999999999999'], [0 => 'int'], 'Invalid int argument'];
        yield 'infinite float' => [[0 => '1e999'], [0 => 'float'], 'Invalid float argument'];
        yield 'malformed box' => [[0 => '10x20x30'], [0 => 'box'], 'Invalid box argument'];
        yield 'non-numeric box axis' => [[0 => '10oopsx20'], [0 => 'box'], 'Invalid box argument'];
        yield 'zero box axis' => [[0 => '0x20'], [0 => 'box'], 'Invalid box argument'];
        yield 'incomplete size' => [[0 => '10x'], [0 => 'size'], 'Invalid size argument'];
    }

    /**
     * @param array<array-key, string> $arguments
     * @param array<array-key, string> $schema
     */
    #[DataProvider('invalidArguments')]
    public function testItRejectsValuesThatDoNotMatchTheOperationSchema(array $arguments, array $schema, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Arguments::check($arguments, $schema);
    }
}
