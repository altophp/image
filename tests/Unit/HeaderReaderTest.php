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
use Alto\Image\Internal\ExifReader;
use Alto\Image\Internal\HeaderReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * getimagesize() plus a magic table, and no extension of any kind.
 *
 * This is what makes "zero required extensions" true rather than aspirational.
 */
#[CoversClass(HeaderReader::class)]
#[CoversClass(ExifReader::class)]
final class HeaderReaderTest extends TestCase
{
    public function testItReadsAPngFromItsFirstThirtyBytes(): void
    {
        $metadata = HeaderReader::read($this->png(1200, 800), 'inline');

        self::assertSame(Format::Png, $metadata->format);
        self::assertSame('1200x800', (string) $metadata->size);
    }

    public function testItReadsAnSvgWhichHasNoMagicBytesAtAll(): void
    {
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80"></svg>' => '120x80',
            '<?xml version="1.0"?><svg viewBox="0 0 300 150"></svg>' => '300x150',
            '<svg width="10em" height="5em" viewBox="0 0 400 200"></svg>' => '400x200',
        ] as $svg => $expected) {
            $metadata = HeaderReader::read($svg, 'inline svg');

            self::assertSame(Format::Svg, $metadata->format);
            self::assertSame($expected, (string) $metadata->size);
        }
    }

    public function testJpegColourSpacesComeFromTheirComponentCount(): void
    {
        self::assertSame('gray', HeaderReader::read($this->jpegWithChannels(1), 'grayscale jpeg')->colourSpace);
        self::assertSame('cmyk', HeaderReader::read($this->jpegWithChannels(4), 'cmyk jpeg')->colourSpace);
    }

    /**
     * HEIC and AVIF are both ISO base media files, told apart by their brand.
     */
    #[DataProvider('brands')]
    public function testIsoBaseMediaFilesAreToldApartByBrand(string $brand, Format $expected): void
    {
        $metadata = HeaderReader::read($this->isoBmff($brand, 4032, 3024), 'inline');

        self::assertSame($expected, $metadata->format);
        self::assertSame('4032x3024', (string) $metadata->size);
    }

    /**
     * @return iterable<string, array{string, Format}>
     */
    public static function brands(): iterable
    {
        yield 'avif' => ['avif', Format::Avif];
        yield 'heic' => ['heic', Format::Heic];
        yield 'mif1' => ['mif1', Format::Heic];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'nothing at all' => [''];
        yield 'a sentence' => ['This is not an image, it is a sentence about one.'];
        yield 'a png signature and nothing else' => ["\x89PNG\x0D\x0A\x1A\x0A"];
        yield 'a jpeg signature and nothing else' => ["\xFF\xD8\xFF"];
        yield 'an ftyp box with an unknown brand' => ["\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00"];
        yield 'an avif brand without an ispe box' => ["\x00\x00\x00\x18ftypavif\x00\x00\x00\x00"];
        yield 'a truncated avif ispe box' => ["\x00\x00\x00\x18ftypavif\x00\x00\x00\x00ispe"];
        yield 'a zero-width avif ispe box' => ["\x00\x00\x00\x18ftypavif\x00\x00\x00\x00ispe\x00\x00\x00\x00" . pack('NN', 0, 10)];
        yield 'a jxl container without dimensions' => ["\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A"];
        yield 'an xbm unsupported by the public format enum' => ["#define x_width 1\n#define x_height 1\nstatic char x_bits[] = { 0x00 };"];
        yield 'an svg with zero dimensions' => ['<svg width="0" height="1"></svg>'];
        yield 'an svg with a zero-width viewbox' => ['<svg viewBox="0 0 0 1"></svg>'];
        yield 'a zero-width png' => ["\x89PNG\x0D\x0A\x1A\x0A\x00\x00\x00\x0DIHDR\x00\x00\x00\x00\x00\x00\x00\x0A\x08\x02\x00\x00\x00"];
    }

    #[DataProvider('garbage')]
    public function testGarbageReturnsNullRatherThanGuessing(string $bytes): void
    {
        self::assertNull(HeaderReader::tryRead($bytes));
    }

    public function testTheOrientationTagIsReadWithoutExtExif(): void
    {
        foreach (range(1, 8) as $orientation) {
            self::assertSame($orientation, ExifReader::orientation($this->jpegWithOrientation($orientation)));
        }
    }

    /**
     * Every shape a fuzzer produces returns "upright", because a lie about
     * orientation is a rotated thumbnail and a crash is an outage.
     */
    #[DataProvider('hostileExif')]
    public function testAHostileExifSegmentReturnsUpright(string $jpeg): void
    {
        self::assertSame(1, ExifReader::orientation($jpeg));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileExif(): iterable
    {
        $tiff = static fn(string $body): string => "\xFF\xD8\xFF\xE1" . pack('n', \strlen($body) + 8) . "Exif\x00\x00" . $body;

        yield 'no exif at all' => ["\xFF\xD8\xFF\xDB\x00\x43"];
        yield 'a broken marker chain' => ["\xFF\xD8\x00\x00\x00\x00"];
        yield 'marker padding before end of image' => ["\xFF\xD8\xFF\xFF\xD9\x00\x00"];
        yield 'a segment shorter than its length field' => ["\xFF\xD8\xFF\xE1\x00\x01"];
        yield 'a truncated big-endian tiff header' => [$tiff('MM')];
        yield 'a byte order nobody uses' => [$tiff("XX\x00\x2A\x00\x00\x00\x08\x00\x01")];
        yield 'an ifd offset past the segment' => [$tiff("MM\x00\x2A\x7F\xFF\xFF\xFF\x00\x01")];
        yield 'an ifd offset inside the header' => [$tiff("MM\x00\x2A\x00\x00\x00\x02\x00\x01")];
        yield 'an entry count that runs off the end' => [$tiff("MM\x00\x2A\x00\x00\x00\x08\xFF\xFF")];
        yield 'a segment claiming more than it has' => ["\xFF\xD8\xFF\xE1\xFF\xFDExif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x01"];
        yield 'an orientation of forty-two' => [self::exif(42)];
        yield 'an orientation of zero' => [self::exif(0)];
        yield 'the wrong tag type' => [self::exif(6, "\x00\x04")];
        yield 'an ifd without an orientation tag' => [$tiff("MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x13\x00\x03\x00\x00\x00\x01\x00\x01\x00\x00")];
    }

    private function png(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\x0D\x0A\x1A\x0A" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    }

    private function isoBmff(string $brand, int $width, int $height): string
    {
        return pack('N', 24) . 'ftyp' . $brand . pack('N', 0) . $brand . 'mif1'
            . pack('N', 20) . 'ispe' . "\x00\x00\x00\x00" . pack('NN', $width, $height);
    }

    private function jpegWithOrientation(int $orientation): string
    {
        return self::exif($orientation);
    }

    private function jpegWithChannels(int $channels): string
    {
        $jpeg = "\xFF\xD8\xFF\xC0" . pack('nCnnC', 8 + 3 * $channels, 8, 2, 2, $channels);

        for ($channel = 1; $channel <= $channels; ++$channel) {
            $jpeg .= pack('CCC', $channel, 0x11, 0);
        }

        return $jpeg . "\xFF\xD9";
    }

    private static function exif(int $orientation, string $type = "\x00\x03"): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01"
            . "\x01\x12" . $type . "\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00"
            . "\x00\x00\x00\x00";

        $payload = "Exif\x00\x00" . $tiff;

        return "\xFF\xD8\xFF\xE1" . pack('n', \strlen($payload) + 2) . $payload;
    }
}
