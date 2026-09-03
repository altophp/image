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
use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Metadata::class)]
final class MetadataTest extends TestCase
{
    /**
     * Values five through eight exchange the axes, and a projection that ignores
     * them reports a portrait photograph as landscape.
     */
    #[DataProvider('orientations')]
    public function testTheQuarterTurnsExchangeTheAxes(int $orientation, bool $transposed, string $displayed): void
    {
        $metadata = new Metadata(new Size(160, 120), Format::Jpeg, orientation: $orientation);

        self::assertSame($transposed, $metadata->isTransposed());
        self::assertSame($displayed, (string) $metadata->displaySize());
    }

    /**
     * @return iterable<string, array{int, bool, string}>
     */
    public static function orientations(): iterable
    {
        yield '1 upright' => [1, false, '160x120'];
        yield '2 mirrored' => [2, false, '160x120'];
        yield '3 upside down' => [3, false, '160x120'];
        yield '4 mirrored upside down' => [4, false, '160x120'];
        yield '5 transposed' => [5, true, '120x160'];
        yield '6 quarter turn' => [6, true, '120x160'];
        yield '7 transverse' => [7, true, '120x160'];
        yield '8 three quarter turn' => [8, true, '120x160'];
    }

    /**
     * A Plan orients once before it projects, which is what keeps the transform
     * string free of an `orient` step it would have to carry on every URL.
     */
    public function testOrientingSpendsTheTagAndSwapsTheAxes(): void
    {
        $oriented = (new Metadata(new Size(160, 120), Format::Jpeg, orientation: 6))->oriented();

        self::assertSame('120x160', (string) $oriented->size);
        self::assertSame(1, $oriented->orientation);
        self::assertSame($oriented, $oriented->oriented(), 'Orienting twice is not two turns.');
    }

    /**
     * Nullable fields remain unchanged through with() and have explicit removal methods.
     */
    public function testNullableFieldsAreExplicitlyRemoved(): void
    {
        $metadata = new Metadata(new Size(10, 10), Format::Jpeg, icc: 'Adobe RGB', bytes: 4096);

        self::assertSame('Adobe RGB', $metadata->with()->icc, 'A bare with() removed something.');
        self::assertSame(4096, $metadata->with()->bytes);
        self::assertSame('Adobe RGB', $metadata->with(icc: null)->icc);
        self::assertSame(4096, $metadata->with(bytes: null)->bytes);
        self::assertNull($metadata->withoutIcc()->icc);
        self::assertNull($metadata->withoutBytes()->bytes);
        self::assertSame('sRGB', $metadata->with(icc: 'sRGB')->icc);
    }

    public function testImpossibleHeaderStateIsRefused(): void
    {
        foreach ([
            static fn(): Metadata => new Metadata(new Size(1, 1), Format::Png, frames: 0),
            static fn(): Metadata => new Metadata(new Size(1, 1), Format::Png, orientation: 9),
            static fn(): Metadata => new Metadata(new Size(1, 1), Format::Png, colourSpace: 'display-p3'),
            static fn(): Metadata => new Metadata(new Size(1, 1), Format::Png, bytes: -1),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Impossible image metadata was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testItDescribesItselfInOneLine(): void
    {
        self::assertSame('jpeg 800x600', (string) new Metadata(new Size(800, 600), Format::Jpeg));
        self::assertSame('png 800x600 alpha', (string) new Metadata(new Size(800, 600), Format::Png, hasAlpha: true));
        self::assertSame('gif 32x32 3f', (string) new Metadata(new Size(32, 32), Format::Gif, frames: 3));
    }
}
