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

namespace Alto\Image\Tests\Driver\Imagick;

use Alto\Image\Driver\Imagick\ShrinkOnLoad;
use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\Operation\Placement;
use Alto\Image\Size;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ShrinkOnLoad::class)]
final class ShrinkOnLoadTest extends SourceClassTestCase
{
    protected const string SUBJECT = ShrinkOnLoad::class;

    public function testChoosesTheSmallestDecodeRungThatCoversEveryOutput(): void
    {
        $source = new Metadata(new Size(4000, 3000), Format::Jpeg);

        self::assertSame('1000x750', ShrinkOnLoad::hint($source, [Placement::scale(new Size(1000, 750))], self::anyRung()));
        self::assertEquals(new Size(501, 376), ShrinkOnLoad::at(new Size(4001, 3001), 1));
    }

    public function testClimbsPastARungThatMovesAPromisedSize(): void
    {
        $source = new Metadata(new Size(563, 678), Format::Jpeg);
        $placements = [Placement::scale(new Size(100, 120))];

        // 141x170 is the smallest rung that covers the output, and it is also the
        // rung an aspect-preserving resize rounds to 100x121 on.
        self::assertSame('141x170', ShrinkOnLoad::hint($source, $placements, self::anyRung()));
        self::assertSame('212x255', ShrinkOnLoad::hint($source, $placements, static fn(Size $decoded): bool => 141 !== $decoded->width));
        self::assertNull(ShrinkOnLoad::hint($source, $placements, static fn(): bool => false));
    }

    public function testSkipsUnsupportedOrFullSizeHints(): void
    {
        self::assertNull(ShrinkOnLoad::hint(new Metadata(new Size(4000, 3000), Format::Png), [Placement::scale(new Size(1000, 750))], self::anyRung()));
        self::assertNull(ShrinkOnLoad::hint(new Metadata(new Size(4000, 3000), Format::Jpeg), [Placement::scale(new Size(4000, 3000))], self::anyRung()));
    }

    /**
     * @return \Closure(Size): bool a plan every rung projects the same way
     */
    private static function anyRung(): \Closure
    {
        return static fn(): bool => true;
    }
}
