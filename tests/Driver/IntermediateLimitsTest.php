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

use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Gd\GdPipeline;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Driver\Imagick\ImagickPipeline;
use Alto\Image\Exception\LimitExceededException;
use Alto\Image\Image;
use Alto\Image\Limits;
use Alto\Image\Source;
use Alto\Image\Transform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdPipeline::class)]
#[CoversClass(ImagickPipeline::class)]
#[CoversClass(GdDriver::class)]
#[CoversClass(ImagickDriver::class)]
final class IntermediateLimitsTest extends TestCase
{
    public function testImagickNativeRotationCanvasIsBoundedBeforeConforming(): void
    {
        $image = $this->image('imagick')->transformedBy(Transform::parse('cover=10x10,s:both|rotate=45|crop=1x1', only: ['cover', 'rotate', 'crop']));
        self::assertSame('1x1', (string) $image->within(new Limits(maxPixels: 289))->render()->metadata->size);
        $this->expectException(LimitExceededException::class);
        $image->within(new Limits(maxPixels: 225))->render();
    }

    #[DataProvider('oversizedTransforms')]
    public function testIntermediateGeometryIsChecked(string $driver, string $transform, Limits $limits): void
    {
        $image = $this->image($driver)->within($limits)->transformedBy(Transform::parse($transform, only: ['cover', 'crop', 'extend', 'rotate']));
        $this->expectException(LimitExceededException::class);
        $image->render();
    }

    /**
     * @return iterable<string, array{string, string, Limits}>
     */
    public static function oversizedTransforms(): iterable
    {
        foreach (['gd', 'imagick'] as $driver) {
            yield $driver . '-pixels' => [$driver, 'cover=11x11,s:both|crop=1x1', new Limits(maxPixels: 100)];
            yield $driver . '-axis' => [$driver, 'cover=11x11,s:both|crop=1x1', new Limits(maxDimension: 10)];
            yield $driver . '-extend' => [$driver, 'extend=10|crop=1x1', new Limits(maxPixels: 100)];
            yield $driver . '-rotate' => [$driver, 'cover=10x10,s:both|rotate=45|crop=1x1', new Limits(maxPixels: 100)];
        }
    }

    #[DataProvider('drivers')]
    public function testBoundedIntermediateAndExplicitNonStrictPolicyRemainSupported(string $driver): void
    {
        foreach ([new Limits(maxPixels: 121), new Limits(maxPixels: 100, strict: false)] as $limits) {
            $result = $this->image($driver)->within($limits)->transformedBy(Transform::parse('cover=11x11,s:both|crop=1x1'))->render();
            self::assertSame('1x1', (string) $result->metadata->size);
        }
    }

    #[DataProvider('drivers')]
    public function testGeometryAfterTrimUsesTheActualRaster(string $driver): void
    {
        $this->image($driver);
        $native = imagecreatetruecolor(10, 10);
        imageline($native, 5, 0, 5, 9, 0xFFFFFF);
        ob_start();
        imagepng($native);
        $image = Image::open(Source::bytes((string) ob_get_clean()))
            ->using('gd' === $driver ? new GdDriver() : new ImagickDriver())
            ->within(new Limits(maxPixels: 100))
            ->transformedBy(Transform::parse('trim|inside=10x,s:both|crop=1x1'));
        $this->expectException(LimitExceededException::class);
        $image->render();
    }

    /**
     * @return iterable<array{string}>
     */
    public static function drivers(): iterable
    {
        yield ['gd'];
        yield ['imagick'];
    }

    private function image(string $driver): Image
    {
        if (!extension_loaded('gd') || !extension_loaded($driver)) {
            self::markTestSkipped('The fixture needs GD and the selected driver.');
        }
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        return Image::open(Source::bytes((string) ob_get_clean()))->using('gd' === $driver ? new GdDriver() : new ImagickDriver());
    }
}
