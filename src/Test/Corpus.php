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

namespace Alto\Image\Test;

use Alto\Image\Exception\StoreException;

/**
 * Builds the reproducible fixture corpus used by driver conformance tests.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Corpus
{
    private const string VERSION = 'v1';

    /**
     * The eight EXIF orientations. Every one of them must display identically.
     */
    public const array ORIENTATIONS = [1, 2, 3, 4, 5, 6, 7, 8];

    private ?string $built = null;

    public function __construct(private readonly string $directory) {}

    /**
     * A corpus under the system temp directory, keyed so that two runs share it.
     */
    public static function shared(): self
    {
        return new self(sys_get_temp_dir() . '/alto-image-corpus-' . self::VERSION);
    }

    /**
     * Writes every fixture, once, and hands back the directory.
     */
    public function build(): string
    {
        if (null !== $this->built) {
            return $this->built;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            throw StoreException::notWritable($this->directory, 'create the corpus directory');
        }

        $this->writePhotograph();
        $this->writeCheckerboard();
        $this->writeEdge();
        $this->writeFlat();
        $this->writeAlpha();
        $this->writeBordered();
        $this->writeOrientations();
        $this->writeAnimation();
        $this->writeMalformed();
        $this->writeBombs();

        return $this->built = $this->directory;
    }

    public function path(string $name): string
    {
        return $this->build() . '/' . $name;
    }

    /**
     * The fixtures a driver is expected to read and transform.
     *
     * @return array<string, string> label to path
     */
    public function readable(): array
    {
        $this->build();

        return [
            'photograph jpeg' => $this->path('photo.jpg'),
            'photograph png' => $this->path('photo.png'),
            'checkerboard' => $this->path('checkerboard.png'),
            'hard edge' => $this->path('edge.png'),
            'flat colour' => $this->path('flat.png'),
            'alpha' => $this->path('alpha.png'),
            'bordered' => $this->path('bordered.png'),
            'portrait' => $this->path('portrait.jpg'),
            'one pixel' => $this->path('one-pixel.png'),
        ];
    }

    /**
     * The fixtures expected to throw a package exception.
     *
     * @return array<string, string>
     */
    public function hostile(): array
    {
        $this->build();

        return [
            'empty file' => $this->path('malformed/empty.jpg'),
            'truncated jpeg' => $this->path('malformed/truncated.jpg'),
            'header only' => $this->path('malformed/header-only.png'),
            'not an image' => $this->path('malformed/text.jpg'),
            'zero dimensions' => $this->path('malformed/zero.png'),
            'pixel bomb' => $this->path('bombs/dimensions.png'),
        ];
    }

    /**
     * @return array<int, string> orientation to path
     */
    public function orientations(): array
    {
        $this->build();
        $paths = [];

        foreach (self::ORIENTATIONS as $orientation) {
            $paths[$orientation] = $this->path(\sprintf('orientation/%d.jpg', $orientation));
        }

        return $paths;
    }

    /**
     * Deterministic content with real high-frequency detail, so that a resample
     * that aliases has something to alias.
     */
    private function writePhotograph(): void
    {
        $image = $this->paint(600, 400);
        imagejpeg($image, $this->directory . '/photo.jpg', 92);
        imagepng($image, $this->directory . '/photo.png');

        $portrait = $this->paint(400, 600);
        imagejpeg($portrait, $this->directory . '/portrait.jpg', 92);

        $pixel = imagecreatetruecolor(1, 1);
        imagesetpixel($pixel, 0, 0, (int) imagecolorallocate($pixel, 230, 57, 70));
        imagepng($pixel, $this->directory . '/one-pixel.png');
    }

    /**
     * The one-pixel checkerboard, whose only correct downscale is flat grey.
     *
     * Everything else is aliasing, and this is the fixture that proves a kernel
     * choice rather than asserting it.
     */
    private function writeCheckerboard(): void
    {
        $size = 1024;
        $image = imagecreatetruecolor($size, $size);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        $white = (int) imagecolorallocate($image, 255, 255, 255);

        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                imagesetpixel($image, $x, $y, 0 === ($x + $y) % 2 ? $black : $white);
            }
        }

        imagepng($image, $this->directory . '/checkerboard.png');
    }

    /**
     * A hard vertical edge, which is representable at every scale and must
     * therefore survive every kernel intact.
     */
    private function writeEdge(): void
    {
        $image = imagecreatetruecolor(512, 256);
        imagefilledrectangle($image, 0, 0, 255, 255, (int) imagecolorallocate($image, 0, 0, 0));
        imagefilledrectangle($image, 256, 0, 511, 255, (int) imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $this->directory . '/edge.png');
    }

    private function writeFlat(): void
    {
        $image = imagecreatetruecolor(320, 240);
        imagefilledrectangle($image, 0, 0, 319, 239, (int) imagecolorallocate($image, 230, 57, 70));
        imagepng($image, $this->directory . '/flat.png');
    }

    private function writeAlpha(): void
    {
        $image = imagecreatetruecolor(256, 256);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 255, 255, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 128, 128, 180, 180, (int) imagecolorallocatealpha($image, 29, 53, 87, 0));
        imagefilledellipse($image, 96, 96, 80, 80, (int) imagecolorallocatealpha($image, 230, 57, 70, 63));
        imagepng($image, $this->directory . '/alpha.png');
    }

    /**
     * A picture inside a uniform border, which is what trim exists for.
     */
    private function writeBordered(): void
    {
        $image = imagecreatetruecolor(300, 200);
        imagefilledrectangle($image, 0, 0, 299, 199, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 40, 30, 259, 169, (int) imagecolorallocate($image, 29, 53, 87));
        imagefilledellipse($image, 150, 100, 120, 80, (int) imagecolorallocate($image, 230, 57, 70));
        imagepng($image, $this->directory . '/bordered.png');
    }

    /**
     * The same picture stored eight ways, each with the tag that puts it back.
     *
     * The stored pixels are the inverse of what the tag asks a viewer to do, so a
     * driver that orients correctly turns all eight into the same image and one
     * that has five and seven the wrong way round turns two of them into mirrors.
     */
    private function writeOrientations(): void
    {
        $directory = $this->directory . '/orientation';

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw StoreException::notWritable($directory, 'create the orientation directory');
        }

        $base = $this->paintAsymmetric(160, 120);

        foreach (self::ORIENTATIONS as $orientation) {
            $stored = $this->storedFor($base, $orientation);

            ob_start();
            imagejpeg($stored, null, 95);
            $jpeg = (string) ob_get_clean();

            file_put_contents($directory . '/' . $orientation . '.jpg', $this->withExifOrientation($jpeg, $orientation));
        }
    }

    /**
     * The pixels a file with this tag has to hold for its display to be the base.
     */
    private function storedFor(\GdImage $base, int $orientation): \GdImage
    {
        $image = $this->copy($base);

        // GD rotates anticlockwise, so 270 here is a quarter turn clockwise.
        return match ($orientation) {
            1 => $image,
            2 => $this->mirror($image),
            3 => $this->turn($image, 180.0),
            4 => $this->turn($this->mirror($image), 180.0),
            5 => $this->mirror($this->turn($image, 270.0)),
            6 => $this->turn($image, 90.0),
            7 => $this->mirror($this->turn($image, 90.0)),
            8 => $this->turn($image, 270.0),
            default => $image,
        };
    }

    private function mirror(\GdImage $image): \GdImage
    {
        imageflip($image, \IMG_FLIP_HORIZONTAL);

        return $image;
    }

    private function turn(\GdImage $image, float $degrees): \GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        return false === $rotated ? $image : $rotated;
    }

    /**
     * Wraps a JPEG's first bytes in an APP1 segment carrying one tag.
     *
     * Thirty bytes of TIFF: the byte order, the magic 42, the offset to IFD0, one
     * entry count, the orientation entry, and a null next-IFD pointer.
     */
    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        if (1 === $orientation) {
            return $jpeg;
        }

        $tiff = "MM\x00\x2A"                       // big-endian, magic 42
            . "\x00\x00\x00\x08"                    // IFD0 begins at byte 8
            . "\x00\x01"                            // one entry
            . "\x01\x12"                            // tag 0x0112, orientation
            . "\x00\x03"                            // type 3, SHORT
            . "\x00\x00\x00\x01"                    // one value
            . pack('n', $orientation) . "\x00\x00"    // the value, left-aligned in four bytes
            . "\x00\x00\x00\x00";                   // no next IFD

        $payload = "Exif\x00\x00" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', \strlen($payload) + 2) . $payload;

        // Straight after SOI, which is where a decoder looks first.
        return "\xFF\xD8" . $app1 . substr($jpeg, 2);
    }

    private function writeAnimation(): void
    {
        // Two frames, hand-assembled, because GD writes one GIF at a time. The
        // point is only that a probe reports more than one frame.
        $frames = [];

        foreach ([[230, 57, 70], [29, 53, 87]] as $rgb) {
            $frame = imagecreate(32, 32);
            imagecolorallocate($frame, $rgb[0], $rgb[1], $rgb[2]);
            ob_start();
            imagegif($frame);
            $frames[] = (string) ob_get_clean();
        }

        $first = $frames[0];
        $second = $frames[1];

        // Splice the second frame's image descriptor and data in before the
        // trailer of the first, each behind a graphic control extension.
        $gce = "\x21\xF9\x04\x00\x32\x00\x00\x00";
        $body = substr($first, 0, -1);
        $secondData = substr($second, (int) strpos($second, "\x2C"), -1);

        file_put_contents($this->directory . '/animation.gif', $body . $gce . $secondData . "\x3B");
    }

    private function writeMalformed(): void
    {
        $directory = $this->directory . '/malformed';

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw StoreException::notWritable($directory, 'create the malformed directory');
        }

        file_put_contents($directory . '/empty.jpg', '');
        file_put_contents($directory . '/text.jpg', 'This is not an image, it is a sentence about one.');

        $jpeg = (string) file_get_contents($this->directory . '/photo.jpg');
        file_put_contents($directory . '/truncated.jpg', substr($jpeg, 0, intdiv(\strlen($jpeg), 3)));

        $png = (string) file_get_contents($this->directory . '/photo.png');
        file_put_contents($directory . '/header-only.png', substr($png, 0, 40));

        // A valid PNG signature and IHDR claiming a zero-pixel image.
        file_put_contents($directory . '/zero.png', $this->png(0, 0));
    }

    private function writeBombs(): void
    {
        $directory = $this->directory . '/bombs';

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw StoreException::notWritable($directory, 'create the bombs directory');
        }

        // 60000x60000 is 3.6 billion pixels, which is 14 GB of truecolour. The
        // header is 67 bytes. Limits::maxPixels has to catch this from the header,
        // because on a PHP linked against an external libgd nothing else will.
        file_put_contents($directory . '/dimensions.png', $this->png(60000, 60000));
        file_put_contents($directory . '/ratio.png', $this->png(1, 1_000_000_000));
    }

    /**
     * A PNG signature and IHDR with no image data, for the fixtures whose whole
     * content is a claim about size.
     */
    private function png(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\x0D\x0A\x1A\x0A"
            . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    }

    private function paint(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                imagesetpixel($image, $x, $y, (int) imagecolorallocate(
                    $image,
                    self::channel(127 + 127 * sin($x / 7)),
                    self::channel(127 + 127 * sin($y / 11)),
                    ($x ^ $y) & 0xFF,
                ));
            }
        }

        return $image;
    }

    /**
     * A shape with no symmetry at all, so that every one of the eight dihedral
     * transforms produces different pixels.
     */
    private function paintAsymmetric(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 255, 255, 255));

        $ink = (int) imagecolorallocate($image, 20, 20, 20);
        $accent = (int) imagecolorallocate($image, 230, 57, 70);

        // A capital F: tall on the left, two arms to the right, nothing below.
        imagefilledrectangle($image, 20, 15, 40, 105, $ink);
        imagefilledrectangle($image, 40, 15, 120, 35, $ink);
        imagefilledrectangle($image, 40, 50, 95, 68, $ink);
        imagefilledrectangle($image, 0, 0, 12, 12, $accent);

        return $image;
    }

    /**
     * @return int<0, 255>
     */
    private static function channel(float $value): int
    {
        return max(0, min(255, (int) $value));
    }

    private function copy(\GdImage $image): \GdImage
    {
        $copy = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $copy;
    }
}
