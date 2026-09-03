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
use Alto\Image\Operation\Placement;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Resolved scalar geometry, which is what a driver executes.
 */
#[CoversClass(Placement::class)]
final class PlacementTest extends TestCase
{
    public function testTheOutputIsTheCropOrTheScalePlusThePadding(): void
    {
        self::assertSame('800x600', (string) (new Placement(800, 600))->output());
        self::assertSame('400x300', (string) (new Placement(800, 600, 400, 300, 0, 0))->output());
        self::assertSame('820x610', (string) (new Placement(800, 600, padTop: 5, padRight: 10, padBottom: 5, padLeft: 10))->output());
        self::assertSame('420x310', (string) (new Placement(800, 600, 400, 300, 0, 0, 5, 10, 5, 10))->output());
    }

    public function testANoopIsSameSizeNoCropAndNoPad(): void
    {
        $source = new Size(4000, 3000);

        self::assertTrue((new Placement(4000, 3000))->isNoop($source));
        self::assertFalse((new Placement(800, 600))->isNoop($source));
        self::assertFalse((new Placement(4000, 3000, 100, 100, 0, 0))->isNoop($source));
        self::assertFalse((new Placement(4000, 3000, padTop: 1))->isNoop($source));
    }

    /**
     * Nullability separates "the driver picks" from "there is no crop".
     */
    public function testNullMeansTheDriverResolvesTheOffset(): void
    {
        $deferred = new Placement(1067, 800, 800, 800);
        $decided = new Placement(1067, 800, 800, 800, 133, 0);
        $uncropped = new Placement(800, 600);

        self::assertTrue($deferred->cropIsDeferred());
        self::assertFalse($decided->cropIsDeferred());
        self::assertFalse($uncropped->cropIsDeferred(), 'No crop at all is not a deferred crop.');

        // Whatever the driver decides, the size is already fixed.
        self::assertSame('800x800', (string) $deferred->output());
    }

    public function testImpossiblePlacementStateIsRefused(): void
    {
        foreach ([
            static fn(): Placement => new Placement(0, 10),
            static fn(): Placement => new Placement(10, 10, cropWidth: 5),
            static fn(): Placement => new Placement(10, 10, cropWidth: 0, cropHeight: 5),
            static fn(): Placement => new Placement(10, 10, cropWidth: 5, cropHeight: 5, cropX: 1),
            static fn(): Placement => new Placement(10, 10, cropWidth: 5, cropHeight: 5, cropX: -1, cropY: 0),
            static fn(): Placement => new Placement(10, 10, padTop: -1),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Impossible placement geometry was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
