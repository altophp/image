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

use Alto\Image\Format;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Format::class)]
final class FormatAliasTest extends TestCase
{
    /**
     * @return iterable<string, array{Format, list<string>, list<string>}>
     */
    public static function aliases(): iterable
    {
        yield 'jpeg' => [Format::Jpeg, ['jpe'], ['image/pjpeg']];
        yield 'png' => [Format::Png, [], ['image/x-png']];
        yield 'avif' => [Format::Avif, ['avifs'], ['image/avif-sequence']];
        yield 'heic' => [Format::Heic, ['hif'], ['image/heif', 'image/heic-sequence']];
        yield 'bmp' => [Format::Bmp, ['dib'], ['image/x-ms-bmp']];
        yield 'svg' => [Format::Svg, ['svgz'], []];
    }

    /**
     * @param list<string> $extensions
     * @param list<string> $mimeTypes
     */
    #[DataProvider('aliases')]
    public function testNonCanonicalAliasesResolveWithoutChangingTheCanonicalNames(
        Format $format,
        array $extensions,
        array $mimeTypes,
    ): void {
        foreach ($extensions as $extension) {
            self::assertSame($format, Format::tryFromExtension(' .' . strtoupper($extension) . ' '));
            self::assertSame($format, Format::of($extension));
        }

        foreach ($mimeTypes as $mimeType) {
            self::assertSame($format, Format::tryFromMime(' ' . strtoupper($mimeType) . ' '));
            self::assertSame($format, Format::of($mimeType));
        }

        self::assertSame($format, Format::of($format->extension()));
        self::assertSame($format, Format::of($format->mime()));
    }

    public function testExtensionAndMimeResolversDoNotAcceptEachOthersInputs(): void
    {
        self::assertNull(Format::tryFromExtension('image/jpeg'));
        self::assertNull(Format::tryFromExtension('photo.jpg'));
        self::assertNull(Format::tryFromMime('jpg'));
        self::assertNull(Format::tryFromMime('image/png; charset=utf-8'));
    }
}
