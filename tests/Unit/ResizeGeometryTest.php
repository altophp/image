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

use Alto\Image\Anchor;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Fit;
use Alto\Image\FocalPoint;
use Alto\Image\Focus;
use Alto\Image\Operation\Resize;
use Alto\Image\Scaling;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Resize::class)]
#[CoversClass(Scaling::class)]
final class ResizeGeometryTest extends TestCase
{
    public function testARatioCanBeRemovedExplicitly(): void
    {
        $resize = new Resize(160, 90, ratio: 16 / 9);

        self::assertNull($resize->withoutRatio()->ratio);
        self::assertSame(160, $resize->withoutRatio()->width);
        self::assertSame(90, $resize->withoutRatio()->height);
    }

    /**
     * @return iterable<string, array{Resize, Size, string}>
     */
    public static function boxes(): iterable
    {
        $landscape = new Size(4000, 3000);

        yield 'cover fills the box exactly' => [new Resize(800, 600, Fit::Cover), $landscape, '800x600'];
        yield 'cover crops the overflow' => [new Resize(800, 800, Fit::Cover), $landscape, '800x800'];
        yield 'inside is bounded by the shorter axis' => [new Resize(800, 800, Fit::Inside), $landscape, '800x600'];
        yield 'outside is bounded by the longer axis' => [new Resize(800, 800, Fit::Outside), $landscape, '1067x800'];
        yield 'contain pads to the box' => [new Resize(800, 800, Fit::Contain), $landscape, '800x800'];
        yield 'fill distorts to the box' => [new Resize(800, 800, Fit::Fill), $landscape, '800x800'];
        yield 'width alone keeps the ratio' => [new Resize(1000), $landscape, '1000x750'];
        yield 'height alone keeps the ratio' => [new Resize(height: 750), $landscape, '1000x750'];
        yield 'a ratio alone crops to the largest box that fits' => [new Resize(fit: Fit::Cover, ratio: 16 / 9), $landscape, '4000x2250'];
        yield 'a tall ratio alone is bounded by the height' => [new Resize(fit: Fit::Cover, ratio: 1 / 2), $landscape, '1500x3000'];
        yield 'a ratio under Inside bounds rather than crops' => [new Resize(ratio: 16 / 9), $landscape, '3000x2250'];
    }

    #[DataProvider('boxes')]
    public function testSolvesTheBox(Resize $resize, Size $source, string $expected): void
    {
        self::assertSame($expected, (string) $resize->solve($source)->output());
    }

    /**
     * @return iterable<string, array{Resize, Size, string}>
     */
    public static function scalingPolicies(): iterable
    {
        $small = new Size(400, 300);

        yield 'down refuses to enlarge, and shrinks the box by the same factor' => [
            new Resize(800, 800, Fit::Cover, scaling: Scaling::Down), $small, '300x300',
        ];
        yield 'both enlarges when asked' => [
            new Resize(800, 800, Fit::Cover, scaling: Scaling::Both), $small, '800x800',
        ];
        yield 'up refuses to shrink' => [
            new Resize(200, 200, Fit::Cover, scaling: Scaling::Up), $small, '300x300',
        ];
        yield 'none crops without scaling' => [
            new Resize(200, 200, Fit::Cover, scaling: Scaling::None), $small, '300x300',
        ];
        yield 'down on a source that is already smaller in one axis only' => [
            new Resize(1280, 720, Fit::Cover, scaling: Scaling::Down), new Size(1200, 800), '1200x675',
        ];
        yield 'fill applies the policy per axis, because it is anisotropic' => [
            new Resize(100, 4000, Fit::Fill, scaling: Scaling::Down), new Size(2000, 2000), '100x2000',
        ];
    }

    #[DataProvider('scalingPolicies')]
    public function testAppliesTheScalingPolicy(Resize $resize, Size $source, string $expected): void
    {
        self::assertSame($expected, (string) $resize->solve($source)->output());
    }

    /**
     * The promise Cover and Contain make is the aspect ratio, not the pixel count.
     *
     * When the scale policy forbids the requested size, the box shrinks by the
     * same factor the scale was clamped by, instead of the operation being
     * abandoned or the ratio being silently broken.
     */
    public function testCoverKeepsTheRatioEvenWhenScalingIsClamped(): void
    {
        $output = (new Resize(1280, 720, Fit::Cover, scaling: Scaling::Down))->solve(new Size(1200, 800))->output();

        self::assertSame(round(1280 / 720, 4), round($output->ratio(), 4));
    }

    /**
     * The srcset ladder is what the geometry is for, so every rung must agree.
     */
    public function testEveryRungOfALadderHasTheSameRatio(): void
    {
        $ratios = [];

        foreach ([640, 960, 1280, 1920] as $width) {
            $output = (new Resize($width, (int) round($width * 9 / 16), Fit::Cover, scaling: Scaling::Down))
                ->solve(new Size(1200, 800))
                ->output();

            $ratios[] = round($output->ratio(), 3);
        }

        self::assertCount(1, array_unique($ratios));
        self::assertSame(round(16 / 9, 3), $ratios[0]);
    }

    /**
     * @return iterable<string, array{Anchor, int, int}>
     */
    public static function anchors(): iterable
    {
        // 4000x3000 -> cover 800x800 scales to 1067x800, so 267 pixels of slack on x.
        yield 'center halves the slack' => [Anchor::Center, 133, 0];
        yield 'left takes none of it' => [Anchor::Left, 0, 0];
        yield 'right takes all of it' => [Anchor::Right, 267, 0];
        yield 'top-left takes none of either' => [Anchor::TopLeft, 0, 0];
        yield 'bottom-right takes all of both' => [Anchor::BottomRight, 267, 0];
    }

    #[DataProvider('anchors')]
    public function testGravityIsResolvedInThePureLayer(Anchor $anchor, int $x, int $y): void
    {
        $placement = (new Resize(800, 800, Fit::Cover, gravity: $anchor))->solve(new Size(4000, 3000));

        self::assertSame($x, $placement->cropX);
        self::assertSame($y, $placement->cropY);
    }

    /**
     * Focus is the one gravity the geometry cannot resolve, so the offset is
     * deferred and the SIZE stays fixed.
     */
    public function testFocusDefersTheOffsetButNotTheSize(): void
    {
        $placement = (new Resize(800, 800, Fit::Cover, gravity: Focus::Attention))->solve(new Size(4000, 3000));

        self::assertTrue($placement->cropIsDeferred());
        self::assertNull($placement->cropX);
        self::assertSame(800, $placement->cropWidth);
        self::assertSame(800, $placement->cropHeight);
        self::assertSame('800x800', (string) $placement->output());
    }

    public function testAFocalPointCentersTheCropOnItAndClampsToTheEdges(): void
    {
        $left = (new Resize(800, 800, Fit::Cover, gravity: new FocalPoint(0.0, 0.5)))->solve(new Size(4000, 3000));
        $right = (new Resize(800, 800, Fit::Cover, gravity: new FocalPoint(1.0, 0.5)))->solve(new Size(4000, 3000));

        self::assertSame(0, $left->cropX);
        self::assertSame(267, $right->cropX);
    }

    public function testContainPadsToTheBoxAndTheSidesAddUp(): void
    {
        $placement = (new Resize(800, 800, Fit::Contain, scaling: Scaling::Both))->solve(new Size(4000, 3000));

        self::assertSame(800, $placement->scaleWidth);
        self::assertSame(600, $placement->scaleHeight);
        self::assertSame(100, $placement->padTop);
        self::assertSame(100, $placement->padBottom);
        self::assertSame(0, $placement->padLeft);
        self::assertSame(0, $placement->padRight);
        self::assertSame('800x800', (string) $placement->output());
    }

    public function testContainRespectsTheAnchorWhenItPads(): void
    {
        $placement = (new Resize(800, 800, Fit::Contain, gravity: Anchor::Top, scaling: Scaling::Both))
            ->solve(new Size(4000, 3000));

        self::assertSame(0, $placement->padTop);
        self::assertSame(200, $placement->padBottom);
    }

    /**
     * Doing nothing is the fastest operation there is, and it falls out of the
     * geometry rather than out of a configuration flag.
     */
    public function testASameSizeRequestIsANoop(): void
    {
        $source = new Size(4000, 3000);

        self::assertTrue((new Resize(4000, 3000, Fit::Inside))->solve($source)->isNoop($source));
        self::assertTrue((new Resize(4000, 3000, Fit::Cover))->solve($source)->isNoop($source));
        self::assertFalse((new Resize(800, 600, Fit::Cover))->solve($source)->isNoop($source));
    }

    /**
     * A resize is never allowed to produce a zero-pixel axis, however extreme
     * the reduction, because a 0-width image is a crash in every driver.
     */
    public function testNoAxisEverReachesZero(): void
    {
        foreach ([[10000, 1], [1, 10000], [3, 5000]] as [$width, $height]) {
            $output = (new Resize(1, 1, Fit::Inside))->solve(new Size($width, $height))->output();

            self::assertGreaterThanOrEqual(1, $output->width);
            self::assertGreaterThanOrEqual(1, $output->height);
        }
    }

    public function testARequestWithNoBoxAtAllIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least a width, a height or a ratio');

        new Resize();
    }

    /**
     * Holding the ratio is what lets one `cover(ratio: 16/9)` feed a whole ladder.
     */
    public function testARatioSurvivesUntilAWidthResolvesIt(): void
    {
        $base = new Resize(fit: Fit::Cover, ratio: 16 / 9);

        foreach ([640 => 360, 960 => 540, 1280 => 720, 1920 => 1080] as $width => $height) {
            $rung = $base->with(width: $width);

            self::assertSame($width, $rung->width);
            self::assertSame($height, $rung->height);
        }
    }

    public function testAResolvedRatioWorksFromEitherAxisAndWithFocusPadding(): void
    {
        $fromHeight = (new Resize(ratio: 2.0))->with(height: 100);
        self::assertSame(200, $fromHeight->width);
        self::assertSame('200x100', (string) (new Resize(200, fit: Fit::Fill, ratio: 2.0))->solve(new Size(1000, 1000))->output());
        self::assertSame('200x100', (string) (new Resize(height: 100, fit: Fit::Fill, ratio: 2.0))->solve(new Size(1000, 1000))->output());

        $placement = (new Resize(800, 800, Fit::Contain, gravity: Focus::Entropy, scaling: Scaling::Both))
            ->solve(new Size(4000, 3000));
        self::assertSame(100, $placement->padTop);
    }
}
