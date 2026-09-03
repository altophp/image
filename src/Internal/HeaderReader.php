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

namespace Alto\Image\Internal;

use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\Size;

/**
 * Reads image metadata from bounded header bytes without a rendering driver.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HeaderReader
{
    /**
     * Enough for every container's box structure, and short enough that reading
     * it off a network stream is not a download.
     */
    public const int HEAD = 4096;

    /**
     * What to try when the first read was not enough.
     *
     * A JPEG whose EXIF holds a full-size thumbnail can push its SOF marker past
     * 64 KB, and reading the whole file to find it would give up the one claim
     * this class makes. So the head grows, three times, and then stops.
     *
     * @var list<int>
     */
    public const array HEADS = [4096, 65536, 262144];

    /**
     * The bit widths of a JXL U32 field, selected by two preceding bits.
     *
     * @var list<int>
     */
    private const array JXL_WIDTHS = [9, 13, 18, 30];

    public static function read(string $head, string $origin, ?int $bytes = null): Metadata
    {
        return self::tryRead($head, $bytes) ?? throw CorruptImageException::unreadableHeader($origin);
    }

    public static function tryRead(string $head, ?int $bytes = null): ?Metadata
    {
        if ('' === $head) {
            return null;
        }

        return self::viaGetImageSize($head, $bytes)
            ?? self::viaIsoBmff($head, $bytes)
            ?? self::viaJxl($head, $bytes)
            ?? self::viaSvg($head, $bytes);
    }

    /**
     * Covers JPEG, PNG, GIF, WebP, BMP and AVIF from a string, so the bytes are
     * read once by the caller and never fetched again here.
     */
    private static function viaGetImageSize(string $head, ?int $bytes): ?Metadata
    {
        $info = @getimagesizefromstring($head);

        if (false === $info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        $format = Format::tryFromMime($info['mime']);

        if (null === $format) {
            return null;
        }

        // getimagesize() reports `width="10em"` as ten pixels and exposes the unit
        // separately. Without a font, the viewBox is the authoritative geometry.
        // Whether PHP recognises SVG here depends on how it was built.
        // @codeCoverageIgnoreStart
        if (Format::Svg === $format) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        return new Metadata(
            new Size($info[0], $info[1]),
            $format,
            self::hasAlpha($format, $head, $info),
            self::frames($format, $head),
            self::orientation($format, $head),
            self::colourSpace($format, $head, $info),
            null,
            $bytes,
            self::hasMetadata($format, $head),
        );
    }

    /**
     * HEIC and AVIF are both ISO base media files, so they are told apart by the
     * brand from the ftyp box because the formats share a signature.
     */
    private static function viaIsoBmff(string $head, ?int $bytes): ?Metadata
    {
        if (\strlen($head) < 12 || 'ftyp' !== substr($head, 4, 4)) {
            return null;
        }

        $brand = substr($head, 8, 4);

        $format = match ($brand) {
            'avif', 'avis' => Format::Avif,
            'heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1' => Format::Heic,
            default => null,
        };

        if (null === $format) {
            return null;
        }

        $size = self::ispeSize($head);

        if (null === $size) {
            return null;
        }

        return new Metadata($size, $format, true, self::isoFrames($head), 1, Metadata::SRGB, null, $bytes);
    }

    /**
     * The image spatial extents box, which is where an ISO BMFF image keeps its
     * dimensions. Reading the box tree properly would be a parser; finding the
     * box by its four-character code and taking the two integers after its
     * version and flags is four lines and is what every reader does in practice.
     */
    private static function ispeSize(string $head): ?Size
    {
        $at = strpos($head, 'ispe');

        if (false === $at || \strlen($head) < $at + 16) {
            return null;
        }

        /** @var array{w: int, h: int}|false $unpacked */
        $unpacked = unpack('Nw/Nh', substr($head, $at + 8, 8));

        if (false === $unpacked || $unpacked['w'] < 1 || $unpacked['h'] < 1) {
            return null;
        }

        return new Size($unpacked['w'], $unpacked['h']);
    }

    private static function isoFrames(string $head): int
    {
        return str_contains($head, 'avis') || str_contains($head, 'msf1') ? 2 : 1;
    }

    /**
     * JXL ships in two containers: the bare codestream, whose size is a packed
     * bitfield, and the ISO BMFF box form.
     */
    private static function viaJxl(string $head, ?int $bytes): ?Metadata
    {
        if (str_starts_with($head, "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A")) {
            $size = self::ispeSize($head) ?? self::jxlCodestreamSize(substr($head, (int) strpos($head, 'jxlc') + 8));

            return null === $size ? null : new Metadata($size, Format::Jxl, true, 1, 1, Metadata::SRGB, null, $bytes);
        }

        if (!str_starts_with($head, "\xFF\x0A")) {
            return null;
        }

        $size = self::jxlCodestreamSize(substr($head, 2));

        return null === $size ? null : new Metadata($size, Format::Jxl, true, 1, 1, Metadata::SRGB, null, $bytes);
    }

    /**
     * The SizeHeader bitfield.
     *
     * JXL packs bits least-significant first, so the reader reverses each byte
     * on the way in. Height comes first, then a three-bit ratio selector; a zero
     * selector means the width is spelled out and anything else means it is
     * derived from the height.
     */
    private static function jxlCodestreamSize(string $codestream): ?Size
    {
        if (\strlen($codestream) < 6) {
            return null;
        }

        $bits = '';

        foreach (str_split(substr($codestream, 0, 8)) as $byte) {
            $bits .= strrev(str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT));
        }

        $at = 0;
        $small = 1 === self::bits($bits, $at, 1);
        $height = self::jxlAxis($bits, $at, $small);
        $ratio = self::bits($bits, $at, 3);
        $width = 0 !== $ratio ? self::jxlRatio($ratio, $height) : self::jxlAxis($bits, $at, $small);

        return $width > 0 && $height > 0 ? new Size($width, $height) : null;
    }

    /**
     * One axis: five bits of an eight-pixel multiple in the small form, or a
     * two-bit selector followed by the field it names.
     */
    private static function jxlAxis(string $bits, int &$at, bool $small): int
    {
        if ($small) {
            return (self::bits($bits, $at, 5) + 1) * 8;
        }

        $width = self::JXL_WIDTHS[self::bits($bits, $at, 2)];

        return self::bits($bits, $at, $width) + 1;
    }

    /**
     * Reads one little-endian field out of the reversed bit string, advancing the cursor.
     */
    private static function bits(string $bits, int &$at, int $count): int
    {
        $slice = substr($bits, $at, $count);
        $at += $count;

        return \strlen($slice) < $count ? 0 : (int) bindec(strrev($slice));
    }

    private static function jxlRatio(int $selector, int $height): int
    {
        return match ($selector) {
            1 => $height,
            2 => (int) round($height * 1.2),
            3 => (int) round($height * 4 / 3),
            4 => (int) round($height * 1.5),
            5 => (int) round($height * 16 / 9),
            6 => (int) round($height * 5 / 4),
            7 => $height * 2,
            default => 0,
        };
    }

    /**
     * SVG has no magic bytes, being XML, so it is recognised by its root element
     * and measured from its width, height or viewBox, in that order.
     */
    private static function viaSvg(string $head, ?int $bytes): ?Metadata
    {
        $prefix = ltrim(substr($head, 0, 1024));

        if (!str_starts_with($prefix, '<?xml') && !str_starts_with($prefix, '<svg') && !str_starts_with($prefix, '<!DOCTYPE svg')) {
            return null;
        }

        if (1 !== preg_match('/<svg\b[^>]*>/i', $head, $tag)) {
            return null;
        }

        $width = self::svgLength($tag[0], 'width');
        $height = self::svgLength($tag[0], 'height');

        if (null === $width || null === $height) {
            if (1 !== preg_match('/\bviewBox\s*=\s*["\']?\s*([-\d.eE]+)[\s,]+([-\d.eE]+)[\s,]+([\d.eE]+)[\s,]+([\d.eE]+)/i', $tag[0], $box)) {
                return null;
            }

            $width ??= (int) round((float) $box[3]);
            $height ??= (int) round((float) $box[4]);
        }

        if ($width < 1 || $height < 1) {
            return null;
        }

        // SVG geometry supports layout planning, signatures and filenames even
        // when no installed driver can rasterise the source.
        return new Metadata(new Size($width, $height), Format::Svg, true, 1, 1, Metadata::SRGB, null, $bytes);
    }

    /**
     * One length off the root element, in pixels, or null when it is not in pixels.
     *
     * Two things to get wrong here and both are quiet. `stroke-width` ends in
     * "width", so the attribute name needs a left boundary that a hyphen does not
     * satisfy. And `width="10em"` is not ten pixels: any unit other than px means
     * the number is meaningless without a font or a viewport, and the viewBox is
     * the authoritative geometry in that case. Returning null is how it gets used.
     */
    private static function svgLength(string $tag, string $attribute): ?int
    {
        if (1 !== preg_match('/(?<![-\w])' . $attribute . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $found)) {
            return null;
        }

        $value = trim(('' !== ($found[1] ?? '')) ? $found[1] : ($found[2] ?? ''));

        if (1 !== preg_match('/^([0-9]*\.?[0-9]+)(px)?$/', $value, $number)) {
            return null;
        }

        $pixels = (int) round((float) $number[1]);

        return $pixels > 0 ? $pixels : null;
    }

    /**
     * Whether there is anything here for MetadataPolicy::Strip to remove.
     *
     * It decides whether a same-size same-format request can be a byte copy, so
     * a false positive costs a needless re-encode and a false negative leaks GPS
     * coordinates to a browser. It errs towards the re-encode.
     */
    private static function hasMetadata(Format $format, string $head): bool
    {
        return match ($format) {
            Format::Jpeg => str_contains($head, "Exif\x00\x00")
                || str_contains($head, 'http://ns.adobe.com/xap/')
                || str_contains($head, 'Photoshop 3.0'),
            Format::Png => str_contains($head, 'eXIf') || str_contains($head, 'iTXt') || str_contains($head, 'tEXt'),
            Format::Webp => str_contains($head, 'EXIF') || str_contains($head, 'XMP '),
            Format::Tiff, Format::Heic, Format::Avif, Format::Jxl => true,
            default => false,
        };
    }

    private static function hasAlpha(Format $format, string $head, mixed $info): bool
    {
        if (!$format->supportsAlpha()) {
            return false;
        }

        return match ($format) {
            // Colour type 4 is grey plus alpha and 6 is truecolour plus alpha;
            // type 3 is a palette, which carries alpha in a tRNS chunk.
            Format::Png => \in_array(\ord($head[25] ?? "\x00"), [4, 6], true) || str_contains($head, 'tRNS'),
            Format::Gif => str_contains(substr($head, 0, 1024), "\x21\xF9"),
            Format::Webp => 'VP8L' === substr($head, 12, 4)
                || ('VP8X' === substr($head, 12, 4) && 0 !== (\ord($head[20] ?? "\x00") & 0x10)),
            Format::Avif, Format::Heic, Format::Jxl, Format::Tiff => true,
            default => \is_array($info) && isset($info['channels']) && 4 === $info['channels'],
        };
    }

    private static function frames(Format $format, string $head): int
    {
        return match ($format) {
            // Two graphic control extensions means at least two frames, and the
            // exact count needs the whole file, which is not what a header read is.
            Format::Gif => substr_count($head, "\x21\xF9") > 1 ? 2 : 1,
            Format::Webp => 'VP8X' === substr($head, 12, 4) && 0 !== (\ord($head[20] ?? "\x00") & 0x02) ? 2 : 1,
            default => 1,
        };
    }

    private static function orientation(Format $format, string $head): int
    {
        return match ($format) {
            Format::Jpeg, Format::Tiff => ExifReader::orientation($head),
            default => 1,
        };
    }

    private static function colourSpace(Format $format, string $head, mixed $info): string
    {
        if (Format::Jpeg === $format) {
            return match (\is_array($info) && isset($info['channels']) ? $info['channels'] : 3) {
                1 => Metadata::GRAY,
                4 => Metadata::CMYK,
                default => Metadata::SRGB,
            };
        }

        if (Format::Png === $format) {
            // Colour types 0 and 4 are the greyscale ones.
            return \in_array(\ord($head[25] ?? "\x02"), [0, 4], true) ? Metadata::GRAY : Metadata::SRGB;
        }

        return Metadata::SRGB;
    }
}
