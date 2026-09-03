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

use Alto\Image\Driver\Capabilities;
use Alto\Image\Driver\Gd\Resampler;
use Alto\Image\Driver\Imagick\ResourcePolicy;
use Alto\Image\Driver\Support;
use Alto\Image\Format;
use Alto\Image\Internal\QualitySearch;
use Alto\Image\Operation\Blur;
use Alto\Image\Operation\Resize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Support::class)]
#[CoversClass(Capabilities::class)]
#[CoversClass(QualitySearch::class)]
#[CoversClass(ResourcePolicy::class)]
#[CoversClass(Resampler::class)]
final class DriverContractTest extends TestCase
{
    /**
     * A boolean forces a lie. GD's gaussian blur takes no radius, so a driver
     * asked "can you blur by sigma 3" must either claim an accuracy it does not
     * have or refuse work it can very nearly do.
     */
    public function testSupportHasThreeValuesAndAnOrdering(): void
    {
        self::assertTrue(Support::Exact->isAtLeast(Support::Approximate));
        self::assertTrue(Support::Approximate->isAtLeast(Support::Approximate));
        self::assertFalse(Support::Approximate->isAtLeast(Support::Exact));
        self::assertTrue(Support::No->isAtLeast(Support::No));
        self::assertFalse(Support::No->isAtLeast(Support::Approximate));

        self::assertTrue(Support::Exact->isPossible());
        self::assertTrue(Support::Approximate->isPossible());
        self::assertFalse(Support::No->isPossible());
    }

    /**
     * A chain is as capable as its worst link.
     */
    public function testCombiningTakesTheWeakestAnswer(): void
    {
        self::assertSame(Support::Exact, Support::Exact->and(Support::Exact));
        self::assertSame(Support::Approximate, Support::Exact->and(Support::Approximate));
        self::assertSame(Support::No, Support::Approximate->and(Support::No));
        self::assertSame(Support::No, Support::No->and(Support::Exact));
    }

    public function testCapabilitiesAnswerFromTheirOwnTable(): void
    {
        $capabilities = new Capabilities(
            'fake',
            '1.0',
            [Format::Jpeg, Format::Png],
            [Format::Png],
            [Blur::class => Support::Approximate],
            ['a note for doctor'],
        );

        self::assertTrue($capabilities->isAvailable());
        self::assertTrue($capabilities->canRead(Format::Jpeg));
        self::assertFalse($capabilities->canWrite(Format::Jpeg));
        self::assertSame(Support::Approximate, $capabilities->supports(new Blur(2.0)));
        self::assertSame(Support::No, $capabilities->supports(new Resize(100)), 'An absent class means No.');
        self::assertSame(['jpeg', 'png'], $capabilities->readNames());
        self::assertSame(['png'], $capabilities->writeNames());
    }

    public function testSpecificCapabilityWinsOverABroadInterfaceRegardlessOfOrder(): void
    {
        $capabilities = new Capabilities(
            'fake',
            '1.0',
            [],
            [],
            [\Alto\Image\Operation\OperationInterface::class => Support::Approximate, Blur::class => Support::Exact],
        );

        self::assertSame(Support::Exact, $capabilities->supports(new Blur()));
        self::assertSame(Support::Approximate, $capabilities->supports(new Resize(100)));
    }

    public function testAMissingDriverIsNotAvailableAndSaysSo(): void
    {
        $missing = Capabilities::missing('vips');

        self::assertFalse($missing->isAvailable());
        self::assertSame(['not installed'], $missing->notes);
    }

    public function testImageMagickPolicyReportsOnlyLowerEffectiveLimits(): void
    {
        self::assertSame(
            ['RESOURCETYPE_MEMORY asked for 2,000 and got 1,024: policy.xml is lower and wins.'],
            ResourcePolicy::overridden([
                'width' => ['asked' => 1000, 'got' => 1000],
                'memory' => ['asked' => 2000, 'got' => 1024],
            ]),
        );

        self::assertNull((new \ReflectionMethod(ResourcePolicy::class, 'constant'))->invoke(null, 'unknown'));
    }

    public function testGdResamplerReturnsTheSameHandleAtTheSameSize(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is not installed.');
        }

        $image = imagecreatetruecolor(2, 3);

        self::assertSame($image, Resampler::scale($image, 2, 3));
        self::assertSame('area average (imagecopyresampled)', Resampler::name());
    }

    /**
     * Bytes are not monotonic in quality to the byte, but they are close enough
     * over any interval a binary search steps across.
     */
    public function testItFindsTheBestQualityThatFits(): void
    {
        // A plausible curve: bytes grow with quality, steeply at the top.
        $encode = static fn(int $quality): string => str_repeat('x', 500 + $quality ** 2);

        [$bytes, $quality, $met] = QualitySearch::under(5_000, 95, $encode);

        self::assertTrue($met);
        self::assertLessThanOrEqual(5_000, \strlen($bytes));
        self::assertSame(67, $quality);
        self::assertGreaterThan(5_000, \strlen($encode($quality + 1)), 'One more would not have fit, so this is the best.');
    }

    public function testItStopsAtTheCeilingWhenTheCeilingAlreadyFits(): void
    {
        $calls = 0;
        $encode = static function (int $quality) use (&$calls): string {
            ++$calls;

            return str_repeat('x', 100);
        };

        [, $quality, $met] = QualitySearch::under(5_000, 82, $encode);

        self::assertTrue($met);
        self::assertSame(82, $quality);
        self::assertSame(1, $calls, 'It encoded more than once for a ceiling that already fit.');
    }

    /**
     * Below the floor an image is not a smaller version of itself, it is a
     * different and worse picture, so the honest answer is that it did not fit.
     */
    public function testAnImpossibleCeilingReportsFailureRatherThanGoingLower(): void
    {
        [$bytes, $quality, $met] = QualitySearch::under(10, 95, static fn(int $q): string => str_repeat('x', 1_000));

        self::assertFalse($met);
        self::assertSame(QualitySearch::FLOOR, $quality);
        self::assertSame(1_000, \strlen($bytes));
    }

    public function testItTakesAtMostAHandfulOfEncodes(): void
    {
        $calls = 0;
        $encode = static function (int $quality) use (&$calls): string {
            ++$calls;

            return str_repeat('x', 500 + $quality ** 2);
        };

        QualitySearch::under(5_000, 95, $encode);

        self::assertLessThanOrEqual(9, $calls, 'Encoding is the largest single cost, so the search has to be short.');
    }
}
