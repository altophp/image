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

use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Exception\LimitExceededException;
use Alto\Image\FailOn;
use Alto\Image\Format;
use Alto\Image\Limits;
use Alto\Image\Metadata;
use Alto\Image\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * On a PHP linked against an external libgd, and that is most of them, this is
 * the only decompression-bomb guard the process has.
 */
#[CoversClass(Limits::class)]
#[CoversClass(FailOn::class)]
final class LimitsTest extends TestCase
{
    public function testAPixelBombIsRefusedAndTheMessageSaysWhyItMatters(): void
    {
        $bomb = new Metadata(new Size(60000, 60000), Format::Png);

        try {
            (new Limits())->check($bomb, 'upload.png');
            self::fail('A 3.6 billion pixel image was accepted.');
        } catch (LimitExceededException $refusal) {
            self::assertStringContainsString('60000x60000', $refusal->getMessage());
            self::assertStringContainsString('maxDimension', $refusal->getMessage());
        }
    }

    public function testLimitCeilingsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxPixels must be positive');

        new Limits(maxPixels: 0);
    }

    public function testTheMessageForAPixelCeilingNamesTheOnlyGuardThereIs(): void
    {
        try {
            (new Limits(maxPixels: 1_000_000))->check(new Metadata(new Size(4000, 3000), Format::Jpeg), 'photo.jpg');
            self::fail('Twelve million pixels passed a one million pixel limit.');
        } catch (LimitExceededException $refusal) {
            self::assertStringContainsString('12,000,000 pixels', $refusal->getMessage());
            self::assertStringContainsString('libgd allocates outside memory_limit', $refusal->getMessage());
        }
    }

    /**
     * A 1x2000000000 image is under any sane pixel ceiling by area and is still
     * an attack, which is why there are two numbers rather than one.
     */
    public function testAPathologicalShapeIsCaughtByTheDimensionLimit(): void
    {
        $this->expectException(LimitExceededException::class);

        (new Limits())->check(new Metadata(new Size(1, 1_000_000_000), Format::Png), 'strip.png');
    }

    public function testFramesAndBytesAreLimitedToo(): void
    {
        $limits = new Limits(maxFrames: 10, maxBytes: 1024);

        $this->expectException(LimitExceededException::class);
        $limits->check(new Metadata(new Size(10, 10), Format::Gif, frames: 500), 'animation.gif');
    }

    public function testEncodedBytesHaveTheirOwnLimit(): void
    {
        $this->expectException(LimitExceededException::class);
        $this->expectExceptionMessage('2,048 bytes');

        (new Limits(maxBytes: 1024))->check(new Metadata(new Size(10, 10), Format::Png, bytes: 2048), 'large.png');
    }

    public function testAPolicyThatChecksNothingIsAvailableAndExplicit(): void
    {
        $none = Limits::none();

        // It refuses nothing, and the way it does that is worth reading: every
        // ceiling is PHP_INT_MAX rather than a special case somewhere in check().
        $none->check(new Metadata(new Size(60000, 60000), Format::Png, frames: 10_000), 'trusted.png');

        self::assertSame(\PHP_INT_MAX, $none->maxPixels);
        self::assertSame(\PHP_INT_MAX, $none->maxDimension);
        self::assertSame(FailOn::None, $none->failOn);
        self::assertFalse($none->strict);
    }

    /**
     * No decoder reports a truncated JPEG, so this is checked structurally from
     * eight bytes off the end of the file.
     */
    public function testTruncationIsCaughtFromTheTrailer(): void
    {
        $jpeg = new Metadata(new Size(600, 400), Format::Jpeg);

        (new Limits())->checkComplete($jpeg, "some scan data\xFF\xD9", 'photo.jpg');

        try {
            (new Limits())->checkComplete($jpeg, 'some scan data and then nothing', 'photo.jpg');
            self::fail('A JPEG with no end-of-image marker was accepted.');
        } catch (CorruptImageException $refusal) {
            self::assertStringContainsString('does not end the way a jpeg file ends', $refusal->getMessage());
            self::assertStringContainsString('FailOn::None', $refusal->getMessage(), 'The message must say what would accept it.');
        }
    }

    public function testAFormatWithNoTrailerIsNeverCalledTruncated(): void
    {
        // WebP is a RIFF container whose length is in its header, so there is no
        // end marker to be missing and no truncation to detect this way.
        self::assertNull(Format::Webp->trailer());

        (new Limits())->checkComplete(new Metadata(new Size(10, 10), Format::Webp), 'anything at all', 'photo.webp');
    }

    public function testTheLenientPolicyAcceptsWhateverSurvived(): void
    {
        $lenient = new Limits(failOn: FailOn::None);

        self::assertSame(FailOn::None, $lenient->failOn);

        // The same bytes the default policy refuses two tests above.
        $lenient->checkComplete(new Metadata(new Size(600, 400), Format::Jpeg), 'no marker here', 'photo.jpg');
    }

    /**
     * The policy decides what a decoder warning means, so that GD's wording and
     * ImageMagick's are judged by one rule.
     */
    public function testThePolicyJudgesADecoderWarning(): void
    {
        self::assertFalse(FailOn::None->rejects('Premature end of JPEG file'));
        self::assertTrue(FailOn::Truncated->rejects('Premature end of JPEG file'));
        self::assertTrue(FailOn::Truncated->rejects('gd-jpeg: JPEG library reports unrecoverable error'));
        self::assertFalse(FailOn::Truncated->rejects('gd-jpeg: warning: unknown APP marker'));
        self::assertTrue(FailOn::Warning->rejects('gd-jpeg: warning: unknown APP marker'));
        self::assertFalse(FailOn::Error->rejects('gd-jpeg: warning: unknown APP marker'));
        self::assertTrue(FailOn::Error->rejects('gd-jpeg: fatal decoder error'));
    }

    public function testAnOutputIsCheckedOnlyUnderTheStrictPolicy(): void
    {
        $huge = new Metadata(new Size(60000, 60000), Format::Png);

        (new Limits(strict: false))->checkOutput($huge, 'an output nobody attacked me with');

        $this->expectException(LimitExceededException::class);
        (new Limits())->checkOutput($huge, 'a projected output');
    }
}
