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

use Alto\Image\Analyzer\DominantColors;
use Alto\Image\Analyzer\Raster;
use Alto\Image\Colour;
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Gd\GdPipeline;
use Alto\Image\Driver\Output;
use Alto\Image\Driver\Plan;
use Alto\Image\Driver\Support;
use Alto\Image\Effort;
use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Exception\DriverException;
use Alto\Image\FailOn;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\Limits;
use Alto\Image\MetadataPolicy;
use Alto\Image\Operation\Adjust;
use Alto\Image\Operation\Escape;
use Alto\Image\Operation\Grayscale;
use Alto\Image\Operation\Overlay;
use Alto\Image\Operation\Placement;
use Alto\Image\Operation\Resize;
use Alto\Image\Operation\Rotate;
use Alto\Image\Operation\Trim;
use Alto\Image\Size;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdDriver::class)]
#[CoversClass(GdPipeline::class)]
#[CoversClass(Image::class)]
#[CoversClass(Raster::class)]
final class GdDriverTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        if (!GdDriver::isAvailable()) {
            self::markTestSkipped('ext-gd is not installed.');
        }

        $this->directory = sys_get_temp_dir() . '/alto-gd-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testCapabilitiesDistinguishUnsupportedAndApproximateRequests(): void
    {
        $driver = new GdDriver();

        self::assertSame(Support::No, $driver->canDecode(Format::Svg));
        self::assertSame(Support::No, $driver->canEncode(new Encoding(Format::Svg)));
        self::assertSame(Support::Approximate, $driver->canEncode(new Encoding(Format::Jpeg, metadata: MetadataPolicy::Keep)));
        self::assertSame(Support::Approximate, $driver->canEncode(new Encoding(Format::Jpeg, effort: Effort::Best)));
    }

    public function testConformingPadsOrCropsToThePromisedSize(): void
    {
        $pipeline = new GdPipeline();
        $image = $pipeline->canvas(4, 3, 0xFFFFFFFF);

        self::assertSame($image, $pipeline->place($image, null, Colour::TRANSPARENT, null));
        self::assertSame('6x2', (string) $pipeline->size($pipeline->conform($image, new Size(6, 2), Colour::TRANSPARENT)));
    }

    public function testOverlayCoversBothCompositingModesAndRefusals(): void
    {
        $pipeline = new GdPipeline();
        $mark = $pipeline->canvas(2, 2, 0xFFFF0000);
        $path = $this->directory . '/mark.png';
        imagepng($mark, $path);

        [$merged] = $pipeline->run($pipeline->canvas(8, 8, 0xFFFFFFFF), [new Overlay($path, opacity: 0.5, margin: 1)]);
        [$copied] = $pipeline->run($pipeline->canvas(8, 8, 0xFFFFFFFF), [new Overlay($path)]);

        self::assertSame('8x8', (string) $pipeline->size($merged));
        self::assertSame('8x8', (string) $pipeline->size($copied));

        try {
            $pipeline->run($copied, [new Overlay($this->directory . '/missing.png')]);
            self::fail('A missing overlay was accepted.');
        } catch (DriverException $error) {
            self::assertStringContainsString('not readable', $error->getMessage());
        }

        $invalid = $this->directory . '/invalid.png';
        file_put_contents($invalid, 'not an image');

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('not an image GD can read');
        set_error_handler(static fn(): bool => true);

        try {
            $pipeline->run($copied, [new Overlay($invalid)]);
        } finally {
            restore_error_handler();
        }
    }

    public function testNoopAdjustmentAndInvalidEscapeAreExplicit(): void
    {
        $pipeline = new GdPipeline();
        $image = $pipeline->canvas(2, 2, 0xFFFFFFFF);

        [$same, $notes] = $pipeline->run($image, [new Adjust()]);
        self::assertSame($image, $same);
        self::assertSame([], $notes);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('returned string');
        $pipeline->run($image, [new Escape(static fn(mixed $handle): string => 'wrong', 'bad')]);
    }

    public function testSharedMasterIsOnlyCopiedWhenAnOperationNeedsToMutateIt(): void
    {
        $pipeline = new GdPipeline();
        $master = $pipeline->canvas(8, 4, 0xFFFF0000);

        [$resized] = $pipeline->run($master, [new Resize(4, 2), new Grayscale()], true);
        [$sameSize] = $pipeline->run($master, [new Resize(8, 4), new Grayscale()], true);
        [$detachedNoop] = $pipeline->run($master, [new Resize(8, 4)], true);

        self::assertSame('8x4', (string) $pipeline->size($master));
        self::assertSame(0xFF0000, imagecolorat($master, 0, 0) & 0xFFFFFF);
        self::assertSame('4x2', (string) $pipeline->size($resized));
        self::assertNotSame(0xFF0000, imagecolorat($resized, 0, 0) & 0xFFFFFF);
        self::assertNotSame($master, $sameSize);
        self::assertNotSame(0xFF0000, imagecolorat($sameSize, 0, 0) & 0xFFFFFF);
        self::assertNotSame($master, $detachedNoop);
    }

    public function testTrimKeepsItsChannelToleranceAfterTheNativePrefilter(): void
    {
        $pipeline = new GdPipeline();
        $image = $pipeline->canvas(100, 80, 0xFFFFFFFF);
        imagefilledrectangle($image, 5, 7, 94, 72, (int) imagecolorallocate($image, 245, 245, 245));
        imagefilledrectangle($image, 9, 11, 90, 68, (int) imagecolorallocate($image, 0, 0, 0));

        [$trimmed] = $pipeline->run($image, [new Trim(20)]);
        [$explicit] = $pipeline->run($image, [new Trim(20, 0xFFFFFFFF)]);

        self::assertSame('82x58', (string) $pipeline->size($trimmed));
        self::assertSame(0x000000, imagecolorat($trimmed, 0, 0) & 0xFFFFFF);
        self::assertSame('82x58', (string) $pipeline->size($explicit));
    }

    public function testTheGdResultCanBecomeAnAnalyzerRaster(): void
    {
        $pipeline = new GdPipeline();
        $source = $pipeline->canvas(4, 3, 0xFF123456);
        ob_start();
        imagepng($source);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        $image = Image::open(Source::bytes($bytes))->using(new GdDriver());
        $raster = Raster::of($image, 2);
        self::assertLessThanOrEqual(2, $raster->width);
        self::assertLessThanOrEqual(2, $raster->height);
        self::assertSame('#123456', $image->analyze(new DominantColors(1))[0]['colour']);

        [$unrotated] = $pipeline->run($source, [new Rotate(0)]);
        self::assertSame($source, $unrotated);

        $placement = new Placement(4, 3, 4, 3);
        self::assertSame('4x3', (string) $pipeline->size($pipeline->place($source, $placement, Colour::TRANSPARENT, null)));
    }

    public function testAnImageWithAValidHeaderAndInvalidPixelsIsRejectedByGd(): void
    {
        $this->expectException(CorruptImageException::class);

        Image::open(Source::bytes($this->pngHeader(20, 10)))
            ->using(new GdDriver())
            ->blur()
            ->png()
            ->bytes();
    }

    public function testDecoderWarningsFollowTheConfiguredFailurePolicy(): void
    {
        $driver = new GdDriver();
        $image = (new GdPipeline())->canvas(2, 2, 0xFFFFFFFF);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        $plan = Plan::negotiate(
            Source::bytes($bytes),
            [Output::new()],
            driver: $driver,
            limits: new Limits(failOn: FailOn::Warning),
        );

        $this->expectException(CorruptImageException::class);
        $this->expectExceptionMessage('decoder said');

        (new \ReflectionMethod(GdDriver::class, 'judge'))->invoke($driver, ['decoder warning'], $plan);
    }

    private function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\x0D\x0A\x1A\x0A"
            . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82";
    }
}
