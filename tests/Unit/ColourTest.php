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

use Alto\Image\Colour;
use Alto\Image\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Colour::class)]
final class ColourTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function spellings(): iterable
    {
        yield 'three hex digits' => ['#fff', 0xFFFFFFFF];
        yield 'three hex digits, no hash' => ['fff', 0xFFFFFFFF];
        yield 'four hex digits with alpha' => ['#f008', 0x88FF0000];
        yield 'six hex digits' => ['#e63946', 0xFFE63946];
        yield 'eight hex digits' => ['#e6394680', 0x80E63946];
        yield 'uppercase' => ['#E63946', 0xFFE63946];
        yield 'an rgb call' => ['rgb(230, 57, 70)', 0xFFE63946];
        yield 'an rgba call' => ['rgba(230, 57, 70, 0.5)', 0x80E63946];
        yield 'the modern slash form' => ['rgb(230 57 70 / 0.5)', 0x80E63946];
        yield 'percentages' => ['rgb(100%, 0%, 0%)', 0xFFFF0000];
        yield 'a name' => ['white', 0xFFFFFFFF];
        yield 'transparent' => ['transparent', 0x00000000];
    }

    #[DataProvider('spellings')]
    public function testItReadsEveryFormPeopleWrite(string $spelling, int $packed): void
    {
        self::assertSame($packed, Colour::parse($spelling));
    }

    /**
     * The canonical form is what a transform string carries, so it has to be the
     * shortest one that survives a round trip.
     */
    public function testTheCanonicalFormRoundTrips(): void
    {
        foreach (['e63946', 'ffffff', '000000', 'e6394680', '00000000'] as $hex) {
            self::assertSame($hex, Colour::format(Colour::parse($hex)));
        }
    }

    public function testAnUnreadableColourSaysWhatItWouldHaveAccepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Try #rgb, #rrggbb, #rrggbbaa, rgb(r, g, b)');

        Colour::parse('cerulean');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSpellings(): iterable
    {
        yield 'wrong hex length' => ['#12'];
        yield 'five hex digits' => ['#12345'];
        yield 'seven hex digits' => ['#1234567'];
        yield 'too few function channels' => ['rgb(1, 2)'];
        yield 'too many function channels' => ['rgba(1, 2, 3, 0.5, 9)'];
        yield 'nonnumeric colour channel' => ['rgb(red, 2, 3)'];
        yield 'nonnumeric alpha channel' => ['rgba(1, 2, 3, opaque)'];
    }

    #[DataProvider('malformedSpellings')]
    public function testMalformedColourSyntaxIsRejected(string $spelling): void
    {
        $this->expectException(InvalidArgumentException::class);

        Colour::parse($spelling);
    }

    /**
     * 255 means opaque here, which is the sane convention. GD's is the other one,
     * so GdPipeline converts at the boundary and nothing else has to think about it.
     */
    public function testTheChannelsComeBackOutInTheRightOrder(): void
    {
        $packed = Colour::parse('#e6394680');

        self::assertSame(0x80, Colour::alpha($packed));
        self::assertSame(0xE6, Colour::red($packed));
        self::assertSame(0x39, Colour::green($packed));
        self::assertSame(0x46, Colour::blue($packed));
        self::assertFalse(Colour::isOpaque($packed));
        self::assertTrue(Colour::isOpaque(Colour::parse('#e63946')));
    }
}
