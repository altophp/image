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

namespace Alto\Image\Tests\Driver;

use Alto\Image\Anchor;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Gd\GdPipeline;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Driver\Imagick\ImagickPipeline;
use Alto\Image\Exception\DriverException;
use Alto\Image\Image;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdDriver::class)]
#[CoversClass(GdPipeline::class)]
#[CoversClass(ImagickDriver::class)]
#[CoversClass(ImagickPipeline::class)]
final class OverlayRenderingTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/alto-overlay-' . bin2hex(random_bytes(6));

        if (!mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            self::fail('Could not create the overlay test directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{class-string<GdDriver|ImagickDriver>}>
     */
    public static function drivers(): iterable
    {
        yield 'gd' => [GdDriver::class];
        yield 'imagick' => [ImagickDriver::class];
    }

    /**
     * @param class-string<GdDriver|ImagickDriver> $class
     */
    #[DataProvider('drivers')]
    public function testEveryAnchorPlacesTheOverlayInsideTheMargin(string $class): void
    {
        $driver = self::driver($class);
        $base = $this->fixture('base.png', self::solidPng(10, 8, 255, 255, 255));
        $mark = $this->fixture('mark.png', self::solidPng(2, 2, 255, 0, 0));

        foreach ([
            Anchor::TopLeft->value => [Anchor::TopLeft, 1, 1],
            Anchor::Top->value => [Anchor::Top, 4, 1],
            Anchor::TopRight->value => [Anchor::TopRight, 7, 1],
            Anchor::Left->value => [Anchor::Left, 1, 3],
            Anchor::Center->value => [Anchor::Center, 4, 3],
            Anchor::Right->value => [Anchor::Right, 7, 3],
            Anchor::BottomLeft->value => [Anchor::BottomLeft, 1, 5],
            Anchor::Bottom->value => [Anchor::Bottom, 4, 5],
            Anchor::BottomRight->value => [Anchor::BottomRight, 7, 5],
        ] as $label => [$anchor, $expectedX, $expectedY]) {
            $bytes = Image::open($base)
                ->using($driver)
                ->overlay($mark, $anchor, margin: 1)
                ->png()
                ->bytes();
            $pixels = self::pixels($bytes, $driver);
            $red = [];

            foreach ($pixels as $position => $colour) {
                if ([255, 0, 0] === $colour) {
                    $red[] = $position;
                }
            }

            sort($red);
            $expected = [
                $expectedX . ':' . $expectedY,
                ($expectedX + 1) . ':' . $expectedY,
                $expectedX . ':' . ($expectedY + 1),
                ($expectedX + 1) . ':' . ($expectedY + 1),
            ];
            sort($expected);

            self::assertSame($expected, $red, $label);
            self::assertSame([255, 255, 255], $pixels['0:0'], $label);
        }
    }

    /**
     * @param class-string<GdDriver|ImagickDriver> $class
     */
    #[DataProvider('drivers')]
    public function testOpacityAddsAnAlphaChannelToAnOpaqueRgbOverlay(string $class): void
    {
        $driver = self::driver($class);
        $base = $this->fixture('base.png', self::solidPng(2, 1, 255, 255, 255));
        $mark = $this->fixture('mark.png', self::solidPng(1, 1, 0, 0, 255));

        $bytes = Image::open($base)
            ->using($driver)
            ->overlay($mark, Anchor::TopLeft, 0.5)
            ->png()
            ->bytes();
        $pixel = self::pixels($bytes, $driver)['0:0'];

        self::assertEqualsWithDelta(127.5, $pixel[0], 2.0);
        self::assertEqualsWithDelta(127.5, $pixel[1], 2.0);
        self::assertSame(255, $pixel[2]);
    }

    /**
     * @param class-string<GdDriver|ImagickDriver> $class
     */
    #[DataProvider('drivers')]
    public function testOpacityScalesOpaqueSemitransparentAndTransparentPixels(string $class): void
    {
        $driver = self::driver($class);
        $base = $this->fixture('base.png', self::solidPng(4, 4, 255, 255, 255));
        $mark = $this->fixture('mark.png', self::rgbaPng([
            [0, 0, 255, 255],
            [0, 0, 255, 128],
            [0, 0, 255, 0],
        ]));

        $bytes = Image::open($base)
            ->using($driver)
            ->overlay($mark, Anchor::TopLeft, 0.5)
            ->png()
            ->bytes();
        $pixels = self::pixels($bytes, $driver);

        self::assertEqualsWithDelta(127.5, $pixels['0:0'][0], 2.0);
        self::assertEqualsWithDelta(127.5, $pixels['0:0'][1], 2.0);
        self::assertSame(255, $pixels['0:0'][2]);
        self::assertEqualsWithDelta(191.0, $pixels['1:0'][0], 2.0);
        self::assertEqualsWithDelta(191.0, $pixels['1:0'][1], 2.0);
        self::assertSame(255, $pixels['1:0'][2]);
        self::assertSame([255, 255, 255], $pixels['2:0']);
        self::assertSame([255, 255, 255], $pixels['3:0']);
        self::assertSame([255, 255, 255], $pixels['0:2']);
    }

    /**
     * @param class-string<GdDriver|ImagickDriver> $class
     */
    #[DataProvider('drivers')]
    public function testMissingAndMalformedOverlaysBecomeDriverExceptions(string $class): void
    {
        $driver = self::driver($class);
        $base = $this->fixture('base.png', self::solidPng(4, 4, 255, 255, 255));
        $missing = $this->directory . '/missing.png';
        $invalid = $this->fixture('invalid.png', 'not an image');

        foreach ([$missing => 'reading', $invalid => 'gd' === $driver->name() ? 'decoding' : 'reading'] as $path => $doing) {
            try {
                Image::open($base)->using($driver)->overlay($path)->png()->bytes();
                self::fail('An unreadable overlay was rendered.');
            } catch (DriverException $error) {
                self::assertStringContainsString($driver->name() . ' failed while ' . $doing . ' the overlay', $error->getMessage());
                self::assertStringContainsString($path, $error->getMessage());
            }
        }
    }

    /**
     * @param class-string<GdDriver|ImagickDriver> $class
     */
    private static function driver(string $class): DriverInterface
    {
        if (!$class::isAvailable()) {
            self::markTestSkipped($class . ' is unavailable.');
        }

        return new $class();
    }

    private function fixture(string $name, string $bytes): string
    {
        $path = $this->directory . '/' . $name;

        if (false === file_put_contents($path, $bytes)) {
            self::fail('Could not write the image fixture.');
        }

        return $path;
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    private static function pixels(string $bytes, DriverInterface $driver): array
    {
        if ('gd' === $driver->name()) {
            $image = imagecreatefromstring($bytes);

            if (false === $image) {
                self::fail('GD could not decode the rendered PNG.');
            }

            $pixels = [];

            for ($y = 0; $y < imagesy($image); ++$y) {
                for ($x = 0; $x < imagesx($image); ++$x) {
                    $packed = imagecolorat($image, $x, $y);
                    $pixels[$x . ':' . $y] = [($packed >> 16) & 0xFF, ($packed >> 8) & 0xFF, $packed & 0xFF];
                }
            }

            return $pixels;
        }

        $image = new \Imagick();
        $image->readImageBlob($bytes);
        $pixels = [];

        for ($y = 0; $y < $image->getImageHeight(); ++$y) {
            for ($x = 0; $x < $image->getImageWidth(); ++$x) {
                $colour = $image->getImagePixelColor($x, $y)->getColor();
                $pixels[$x . ':' . $y] = [$colour['r'], $colour['g'], $colour['b']];
            }
        }

        $image->clear();

        return $pixels;
    }

    private static function solidPng(int $width, int $height, int $red, int $green, int $blue): string
    {
        $pixel = pack('C3', $red, $green, $blue);
        $data = str_repeat("\x00" . str_repeat($pixel, $width), $height);
        $header = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', $header)
            . self::chunk('IDAT', (string) gzcompress($data, 6))
            . self::chunk('IEND', '');
    }

    /**
     * @param list<array{int, int, int, int}> $pixels
     */
    private static function rgbaPng(array $pixels): string
    {
        $data = "\x00";

        foreach ($pixels as [$red, $green, $blue, $alpha]) {
            $data .= pack('C4', $red, $green, $blue, $alpha);
        }

        $header = pack('N2C5', \count($pixels), 1, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', $header)
            . self::chunk('IDAT', (string) gzcompress($data, 6))
            . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
