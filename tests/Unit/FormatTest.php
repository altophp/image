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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Format::class)]
final class FormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Format}>
     */
    public static function spellings(): iterable
    {
        yield 'its own name' => ['webp', Format::Webp];
        yield 'an extension' => ['jpg', Format::Jpeg];
        yield 'an extension with a dot' => ['.JPEG', Format::Jpeg];
        yield 'the other jpeg extension' => ['jfif', Format::Jpeg];
        yield 'a mime type' => ['image/avif', Format::Avif];
        yield 'the wrong but common jpeg mime' => ['image/jpg', Format::Jpeg];
        yield 'a heif extension' => ['heif', Format::Heic];
        yield 'a tiff extension' => ['TIF', Format::Tiff];
        yield 'svg' => ['image/svg+xml', Format::Svg];
    }

    #[DataProvider('spellings')]
    public function testItResolvesEveryWayPeopleWriteAFormat(string $spelling, Format $expected): void
    {
        self::assertSame($expected, Format::of($spelling));
    }

    public function testAnUnknownFormatNamesTheOnesItKnows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Known: jpeg, png, webp');

        Format::of('tga');
    }

    public function testAlphaAndAnimationAreProperties(): void
    {
        self::assertFalse(Format::Jpeg->supportsAlpha());
        self::assertTrue(Format::Png->supportsAlpha());
        self::assertTrue(Format::Gif->supportsAnimation());
        self::assertFalse(Format::Png->supportsAnimation());
        self::assertTrue(Format::Svg->isVector());
        self::assertFalse(Format::Png->isVector());
    }

    /**
     * One shared quality constant would either bloat the AVIF or starve the JPEG,
     * because the two reach the same perceived quality at different numbers.
     */
    public function testEachLossyFormatHasItsOwnDefaultQuality(): void
    {
        self::assertGreaterThan(Format::Avif->defaultQuality(), Format::Jpeg->defaultQuality());
        self::assertGreaterThan(Format::Avif->defaultQuality(), Format::Webp->defaultQuality());

        foreach ([Format::Png, Format::Gif, Format::Bmp] as $lossless) {
            self::assertFalse($lossless->isLossy());
            self::assertSame(100, $lossless->defaultQuality());
        }
    }

    /**
     * The trailer is what catches a truncated file, and only the formats that
     * really have one may claim one.
     */
    public function testOnlyTheFormatsWithATrailerClaimOne(): void
    {
        self::assertSame("\xFF\xD9", Format::Jpeg->trailer());
        self::assertSame("IEND\xAE\x42\x60\x82", Format::Png->trailer());
        self::assertSame("\x3B", Format::Gif->trailer());

        foreach ([Format::Webp, Format::Avif, Format::Heic, Format::Jxl, Format::Tiff, Format::Bmp, Format::Svg] as $format) {
            self::assertNull($format->trailer(), \sprintf('%s does not end in a fixed marker.', $format->value));
        }
    }

    public function testTheExtensionIsWhatPeopleActuallyType(): void
    {
        self::assertSame('jpg', Format::Jpeg->extension());
        self::assertSame('tif', Format::Tiff->extension());
        self::assertSame('webp', Format::Webp->extension());
    }

    public function testEveryFormatRoundTripsThroughItsCanonicalExtensionAndMimeType(): void
    {
        foreach (Format::cases() as $format) {
            self::assertSame($format, Format::tryFromExtension($format->extension()));
            self::assertSame($format, Format::tryFromMime($format->mime()));
        }

        self::assertNull(Format::tryFromExtension('unknown'));
        self::assertNull(Format::tryFromMime('application/octet-stream'));
    }
}
