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

use Alto\Image\Driver\Encoding;
use Alto\Image\Effort;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\MetadataPolicy;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Encoding::class)]
#[CoversClass(Effort::class)]
#[CoversClass(MetadataPolicy::class)]
final class EncodingTest extends TestCase
{
    public function testANullFormatMeansWhateverTheSourceIs(): void
    {
        $encoding = new Encoding();

        self::assertSame(Format::Webp, $encoding->formatOr(Format::Webp));
        self::assertSame(Format::Png, $encoding->formatOr(Format::Png));
        self::assertSame(Format::Avif, $encoding->with(format: Format::Avif)->formatOr(Format::Png));
    }

    public function testResolvingAnswersEveryQuestion(): void
    {
        $resolved = (new Encoding())->resolve(Format::Jpeg);

        self::assertSame(Format::Jpeg, $resolved->format);
        self::assertSame(Format::Jpeg->defaultQuality(), $resolved->quality);
    }

    public function testAnImpossibleQualityIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quality falls in [1, 100]');

        new Encoding(Format::Jpeg, 140);
    }

    public function testAnImpossibleByteCeilingIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('byte ceiling must be positive');

        new Encoding(maxBytes: 0);
    }

    /**
     * The second half of the 342x: the geometry says the pixels are unchanged and
     * this says the bytes would be too.
     */
    public function testASameFormatRequestWithNoQualityIsAPassThrough(): void
    {
        $webp = new Metadata(new Size(640, 480), Format::Webp);

        self::assertTrue((new Encoding(Format::Webp))->isPassThrough($webp));
        self::assertTrue((new Encoding())->isPassThrough($webp));
    }

    /**
     * Naming a quality is a request to re-compress, whatever the geometry says.
     * Nothing in a header reveals what quality a file was encoded at, so the
     * honest reading of `->webp(50)` is "do it", not "probably not worth it".
     */
    public function testANamedQualityIsNeverAPassThrough(): void
    {
        $webp = new Metadata(new Size(640, 480), Format::Webp);

        self::assertFalse((new Encoding(Format::Webp, 50))->isPassThrough($webp));
        self::assertFalse((new Encoding(Format::Webp, 100))->isPassThrough($webp));
        self::assertFalse((new Encoding(Format::Webp, maxBytes: 10_000))->isPassThrough($webp));
        self::assertFalse((new Encoding(Format::Webp, lossless: true))->isPassThrough($webp));
    }

    public function testAFormatChangeIsNeverAPassThrough(): void
    {
        self::assertFalse((new Encoding(Format::Avif))->isPassThrough(new Metadata(new Size(640, 480), Format::Webp)));
    }

    /**
     * Stripping metadata is a rewrite, so it can only be skipped when there is
     * nothing there to strip. Copying bytes under MetadataPolicy::Strip is how
     * GPS coordinates reach a browser.
     */
    public function testStrippingDefeatsThePassThroughWhenThereIsSomethingToStrip(): void
    {
        $carrying = new Metadata(new Size(640, 480), Format::Jpeg, hasMetadata: true);
        $bare = new Metadata(new Size(640, 480), Format::Jpeg, hasMetadata: false);
        $profiled = new Metadata(new Size(640, 480), Format::Jpeg, icc: 'Display P3');

        self::assertFalse((new Encoding(metadata: MetadataPolicy::Strip))->isPassThrough($carrying));
        self::assertFalse((new Encoding(metadata: MetadataPolicy::Copyright))->isPassThrough($carrying));
        self::assertTrue((new Encoding(metadata: MetadataPolicy::Strip))->isPassThrough($bare));
        self::assertTrue((new Encoding(metadata: MetadataPolicy::Keep))->isPassThrough($carrying));
        self::assertFalse((new Encoding(metadata: MetadataPolicy::Strip))->isPassThrough($profiled));
        self::assertTrue((new Encoding(metadata: MetadataPolicy::ColourProfile))->isPassThrough($profiled));
    }

    public function testMetadataPoliciesProjectOnlyWhatTheyActuallyKeep(): void
    {
        $source = new Metadata(new Size(10, 10), Format::Jpeg, icc: 'Adobe RGB', hasMetadata: true);

        self::assertNull(MetadataPolicy::Strip->project($source)->icc);
        self::assertFalse(MetadataPolicy::Strip->project($source)->hasMetadata);
        self::assertSame('Adobe RGB', MetadataPolicy::ColourProfile->project($source)->icc);
        self::assertFalse(MetadataPolicy::ColourProfile->project($source)->hasMetadata);
        self::assertNull(MetadataPolicy::Copyright->project($source)->icc);
        self::assertTrue(MetadataPolicy::Copyright->project($source)->hasMetadata);
        self::assertSame($source, MetadataPolicy::Keep->project($source));
        self::assertFalse(MetadataPolicy::Copyright->keepsProfile());
        self::assertTrue(MetadataPolicy::Copyright->keepsMetadata());
        self::assertFalse(MetadataPolicy::Copyright->keepsEverything());
    }

    public function testTwoEncodingsThatDifferAtAllHaveDifferentSignatures(): void
    {
        $base = new Encoding(Format::Jpeg, 80);

        foreach ([
            $base->with(quality: 81),
            new Encoding(Format::Avif, 80),
            $base->with(effort: Effort::Best),
            $base->with(metadata: MetadataPolicy::Keep),
            $base->with(maxBytes: 40_000),
            new Encoding(Format::Webp, lossless: true),
            $base->with(progressive: false),
        ] as $variant) {
            self::assertNotSame($base->signature(), $variant->signature());
        }
    }

    public function testTheByteLimitCanBeRemovedExplicitly(): void
    {
        $encoding = new Encoding(Format::Webp, maxBytes: 40_000);

        self::assertSame(40_000, $encoding->with(maxBytes: null)->maxBytes);
        self::assertNull($encoding->withoutMaxBytes()->maxBytes);
    }

    public function testNullableChoicesHaveExplicitResetMethods(): void
    {
        $encoding = new Encoding(Format::Webp, 80, maxBytes: 40_000);

        self::assertNull($encoding->withSourceFormat()->format);
        self::assertNull($encoding->withDefaultQuality()->quality);
        self::assertNull($encoding->withoutMaxBytes()->maxBytes);
    }

    public function testLosslessFormatsRejectLossyOptions(): void
    {
        foreach ([
            static fn(): Encoding => new Encoding(Format::Png, quality: 80),
            static fn(): Encoding => new Encoding(Format::Png, maxBytes: 1000),
            static fn(): Encoding => new Encoding(Format::Webp, progressive: false),
            static fn(): Encoding => new Encoding(Format::Webp, quality: 80, lossless: true),
            static fn(): Encoding => new Encoding(Format::Png, lossless: true),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('A meaningless codec option was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEffortIsTwoKnobsWithSaneEnds(): void
    {
        self::assertGreaterThan(Effort::Best->speed(), Effort::Fast->speed(), 'Fast has to be the faster speed number.');
        self::assertGreaterThan(Effort::Fast->compression(), Effort::Best->compression(), 'Best has to compress harder.');
    }

    public function testItDescribesItselfReadably(): void
    {
        self::assertSame('webp q80', (string) new Encoding(Format::Webp, 80));
        self::assertSame('avif q40 best', (string) new Encoding(Format::Avif, 40, Effort::Best));
        self::assertSame('jpeg q82 <=40000B', (string) new Encoding(Format::Jpeg, 82, maxBytes: 40_000));
        self::assertSame('png', (string) new Encoding(Format::Png));
        self::assertSame('source', (string) new Encoding());
    }
}
