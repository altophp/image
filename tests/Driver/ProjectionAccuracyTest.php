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

use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Driver\Support;
use Alto\Image\Image;
use Alto\Image\Test\Corpus;
use Alto\Image\Test\ImageAssertions;
use Alto\Image\Transform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The test the library lives or dies by, run across every installed driver at once.
 *
 * The conformance kit asks each driver this question separately, which is what a
 * third-party driver inherits. This asks it of all of them together, over the
 * whole corpus and the whole transform vocabulary, because the claim is not
 * "GD is accurate", it is "the number size() returns is the number you get".
 *
 * If this file ever goes red, an Image told someone their picture would be
 * 1280x720 and something else came out, and that reaches a browser as layout
 * shift no CSS can fix.
 */
final class ProjectionAccuracyTest extends TestCase
{
    use ImageAssertions;

    private static ?Corpus $corpus = null;

    /**
     * @return iterable<string, array{DriverInterface}>
     */
    public static function drivers(): iterable
    {
        yield 'gd' => [new GdDriver()];
        yield 'imagick' => [new ImagickDriver()];
    }

    /**
     * Every driver, every fixture, every transform in the vocabulary.
     */
    #[DataProvider('drivers')]
    public function testTheProjectionIsNeverWrong(DriverInterface $driver): void
    {
        if (!$driver->capabilities()->isAvailable()) {
            self::markTestSkipped(\sprintf('%s is not installed here.', $driver->name()));
        }

        $checked = 0;

        foreach (self::transforms() as $transform) {
            $parsed = Transform::parse($transform);

            foreach (self::corpus()->readable() as $label => $path) {
                $image = Image::open($path)->using($driver)->transformedBy($parsed)->png();

                if (Support::No === $driver->canDecode($image->sourceMetadata()->format)) {
                    continue;
                }

                $projected = $image->size();
                $result = $image->render();

                self::assertSame(
                    (string) $projected,
                    (string) $result->size(),
                    \sprintf('%s: "%s" on %s projected %s and produced %s.', $driver->name(), $transform, $label, $projected, $result->size()),
                );

                // The Result reports one number; the encoded file has to be that number.
                self::assertImageSize($result->bytes, $projected, \sprintf('%s: "%s" on %s', $driver->name(), $transform, $label));

                ++$checked;
            }
        }

        self::assertGreaterThan(100, $checked, 'The matrix collapsed to almost nothing, which means it is not testing anything.');
    }

    /**
     * The whole vocabulary, in the combinations that break projections: a scale
     * policy that clamps, a ratio that has to survive, a rotation whose bounding
     * box every library computes differently, and chains of all three.
     *
     * @return list<string>
     */
    private static function transforms(): array
    {
        return [
            'cover=100x100',
            'cover=137x91',
            'cover=91x137,g:bottom-right',
            'cover=800x800,s:both',
            'cover=800x800',
            'inside=137x137',
            'inside=5000x5000,s:both',
            'outside=137x137,s:both',
            'contain=137x91,bg:ffffff,s:both',
            'fill=137x91,s:both',
            'crop=97x83',
            'crop=97x83,x:7,y:11',
            'crop=5000x5000',
            'extend=7',
            'extend=0,t:13,r:5,b:3,l:11',
            'rotate=90',
            'rotate=180',
            'rotate=270',
            'rotate=17',
            'rotate=43,bg:ffffff',
            'rotate=89.5',
            'flip=h|flip=v',
            'inside=97x97|rotate=90|extend=5',
            'inside=97x97|rotate=23|crop=50x50',
            'cover=100x60,s:both|rotate=90|contain=80x80,s:both',
            'extend=9,bg:000000|cover=64x64,s:both|rotate=180',
        ];
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= Corpus::shared();
    }
}
