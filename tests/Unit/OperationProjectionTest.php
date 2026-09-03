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
use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\Operation\Blur;
use Alto\Image\Operation\Crop;
use Alto\Image\Operation\Extend;
use Alto\Image\Operation\Flatten;
use Alto\Image\Operation\IccConvert;
use Alto\Image\Operation\Orient;
use Alto\Image\Operation\Rotate;
use Alto\Image\Operation\Trim;
use Alto\Image\Size;
use Alto\Image\Transform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every operation projects from the header alone, and never decodes.
 */
#[CoversClass(Rotate::class)]
#[CoversClass(Crop::class)]
#[CoversClass(Extend::class)]
#[CoversClass(Trim::class)]
#[CoversClass(Orient::class)]
#[CoversClass(Flatten::class)]
#[CoversClass(IccConvert::class)]
#[CoversClass(Blur::class)]
final class OperationProjectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function projections(): iterable
    {
        yield 'a quarter turn exchanges the axes' => ['rotate=90', '3000x4000'];
        yield 'a half turn does not' => ['rotate=180', '4000x3000'];
        yield 'a free angle grows the canvas' => ['rotate=45', '4950x4950'];
        yield 'a tiny angle grows it a little' => ['rotate=1', '4052x3070'];
        yield 'a crop takes a rectangle' => ['crop=800x450', '800x450'];
        yield 'a crop is clamped to the source' => ['crop=9000x9000', '4000x3000'];
        yield 'an extend adds a border' => ['extend=10', '4020x3020'];
        yield 'an asymmetric extend adds what it says' => ['extend=0,t:40,r:20', '4020x3040'];
        yield 'a pixel operation changes nothing' => ['blur=3', '4000x3000'];
        yield 'a chain composes' => ['inside=800x800|rotate=90|extend=10', '620x820'];
    }

    #[DataProvider('projections')]
    public function testTheProjectedSizeIsComputedFromTheHeaderAlone(string $transform, string $expected): void
    {
        $projected = Transform::parse($transform)->project(new Metadata(new Size(4000, 3000), Format::Jpeg));

        self::assertSame($expected, (string) $projected->size);
    }

    /**
     * Ceil rather than round, and the difference is the whole reason this
     * arithmetic lives here: GD ceils its own bounding box and ImageMagick
     * computes another one again, and measured the two disagree by up to two
     * pixels at the same angle.
     */
    public function testTheRotationBoundingBoxNeverClipsACorner(): void
    {
        foreach ([[400, 300], [401, 301], [100, 100]] as [$width, $height]) {
            foreach ([1, 10, 30, 45, 89] as $degrees) {
                $box = (new Rotate($degrees))->boundingBox(new Size($width, $height));
                $radians = deg2rad($degrees);
                $exact = [
                    $width * abs(cos($radians)) + $height * abs(sin($radians)),
                    $width * abs(sin($radians)) + $height * abs(cos($radians)),
                ];

                self::assertGreaterThanOrEqual($exact[0], $box->width, \sprintf('%dx%d at %d clipped horizontally.', $width, $height, $degrees));
                self::assertGreaterThanOrEqual($exact[1], $box->height, \sprintf('%dx%d at %d clipped vertically.', $width, $height, $degrees));
            }
        }
    }

    public function testAnAngleIsNormalisedIntoOneTurn(): void
    {
        self::assertSame(90.0, (new Rotate(450))->degrees);
        self::assertSame(270.0, (new Rotate(-90))->degrees);
        self::assertSame(0.0, (new Rotate(720))->degrees);
        self::assertTrue((new Rotate(-90))->isQuarterTurn());
        self::assertFalse((new Rotate(45))->isQuarterTurn());
    }

    /**
     * Orient is a no-op in the ordinary path, because a Plan orients before it
     * projects. It exists for a transform written by hand and for a chain that
     * turns before it resizes.
     */
    public function testOrientSpendsTheTagAndIsThenIdempotent(): void
    {
        $portrait = new Metadata(new Size(160, 120), Format::Jpeg, orientation: 6);
        $once = (new Orient())->project($portrait);

        self::assertSame('120x160', (string) $once->size);
        self::assertSame(1, $once->orientation);
        self::assertSame('120x160', (string) (new Orient())->project($once)->size);
    }

    /**
     * A trim is an upper bound rather than an answer, and the projection says the
     * upper bound rather than pretending to know.
     */
    public function testATrimProjectsTheSourceUnchanged(): void
    {
        $source = new Metadata(new Size(4000, 3000), Format::Png);

        self::assertSame('4000x3000', (string) (new Trim())->project($source)->size);
        self::assertFalse(Transform::new()->with(new Trim())->isMeasurable());
    }

    public function testFlattenRemovesAlphaOnlyWhenTheBackgroundIsOpaque(): void
    {
        $transparent = new Metadata(new Size(10, 10), Format::Png, hasAlpha: true);

        self::assertFalse((new Flatten(0xFFFFFFFF))->project($transparent)->hasAlpha);
        self::assertTrue((new Flatten(Colour::parse('#ffffff80')))->project($transparent)->hasAlpha);
    }

    public function testPaddingWithATransparentBackgroundIntroducesAlpha(): void
    {
        $opaque = new Metadata(new Size(4000, 3000), Format::Jpeg);

        self::assertTrue(Transform::parse('contain=800x800,s:both')->project($opaque)->hasAlpha);
        self::assertFalse(Transform::parse('contain=800x800,bg:ffffff,s:both')->project($opaque)->hasAlpha);
        self::assertTrue(Transform::parse('extend=10')->project($opaque)->hasAlpha);
        self::assertFalse(Transform::parse('extend=10,bg:000000')->project($opaque)->hasAlpha);
    }

    public function testAColourProfileConversionIsVisibleInTheProjection(): void
    {
        $cmyk = new Metadata(new Size(10, 10), Format::Jpeg, colourSpace: Metadata::CMYK);
        $projected = (new IccConvert('srgb'))->project($cmyk);

        self::assertSame(Metadata::SRGB, $projected->colourSpace);
        self::assertSame('srgb', $projected->icc);
    }

}
