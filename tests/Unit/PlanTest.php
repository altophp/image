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
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Output;
use Alto\Image\Driver\Plan;
use Alto\Image\Driver\Support;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Exception\UnsupportedOperationException;
use Alto\Image\Format;
use Alto\Image\MetadataPolicy;
use Alto\Image\Operation\Blur;
use Alto\Image\Operation\Escape;
use Alto\Image\Operation\IccConvert;
use Alto\Image\Operation\OperationInterface;
use Alto\Image\Operation\Orient;
use Alto\Image\Operation\Resize;
use Alto\Image\Operation\Rotate;
use Alto\Image\Result;
use Alto\Image\Source;
use Alto\Image\Test\Corpus;
use Alto\Image\Transform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Output::class)]
#[CoversClass(Plan::class)]
final class PlanTest extends TestCase
{
    public function testAnExactDriverProducesACompletePlan(): void
    {
        $driver = new NegotiationDriver();
        $plan = Plan::negotiate($this->png(), [Output::new()], candidates: [$driver]);

        self::assertSame($driver, $plan->driver);
        self::assertSame('10x10', (string) $plan->output(0)->size);
        self::assertSame([], $plan->operations(0));
        self::assertTrue($plan->isPassThrough(0));
        self::assertCount(2, Plan::known());
        self::assertNotSame([], Plan::installed());
    }

    public function testAPlanNeedsAnOutputAndChecksIndexes(): void
    {
        try {
            Plan::negotiate($this->png(), [], candidates: [new NegotiationDriver()]);
            self::fail('An empty plan was negotiated.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $plan = Plan::negotiate($this->png(), [Output::new()], candidates: [new NegotiationDriver()]);

        foreach ([$plan->output(...), $plan->operations(...), $plan->isPassThrough(...)] as $invalidIndex) {
            try {
                $invalidIndex(1);
                self::fail('A missing output index was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAnOutputDescribesAndSignsItsCompleteRequest(): void
    {
        $output = Output::new();

        self::assertSame('as-is source', (string) $output);
        self::assertNotSame('', $output->signature());
    }

    public function testApproximateDecodeAndEncodeAreRecordedOnce(): void
    {
        $driver = new NegotiationDriver(decode: Support::Approximate, encode: Support::Approximate);
        $output = Output::new()->with(encoding: new Encoding(Format::Webp));

        $plan = Plan::negotiate($this->png(), [$output], candidates: [$driver]);

        self::assertSame([
            'fixture reads png with losses',
            'fixture writes webp approximately',
        ], $plan->degradations);
    }

    public function testRefusalsDistinguishDecodeEncodeAndOperations(): void
    {
        $cases = [
            'decode' => [
                new NegotiationDriver(decode: Support::No),
                Output::new(),
                'cannot read png',
            ],
            'encode' => [
                new NegotiationDriver(encode: Support::No),
                Output::new()->with(encoding: new Encoding(Format::Jxl)),
                'built against libjxl',
            ],
            'icc' => [
                new NegotiationDriver(operation: Support::No),
                Output::new()->with(transform: Transform::new()->with(new IccConvert())),
                'GD has no concept of a colour profile',
            ],
            'operation' => [
                new NegotiationDriver(operation: Support::No),
                Output::new()->with(transform: Transform::new()->with(new Blur())),
                'no installed driver implements that operation',
            ],
        ];

        foreach ($cases as $label => [$driver, $output, $message]) {
            try {
                Plan::negotiate($this->png(), [$output], candidates: [$driver]);
                self::fail($label . ' refusal was accepted.');
            } catch (UnsupportedOperationException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $label);
            }
        }
    }

    public function testVectorRefusalsNameTheRasterisingSidecar(): void
    {
        $source = Source::bytes('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>', 'icon');

        try {
            Plan::negotiate($source, [Output::new()], candidates: [new NegotiationDriver(decode: Support::No)]);
            self::fail('A vector was accepted by a raster-only driver.');
        } catch (UnsupportedOperationException $error) {
            self::assertStringContainsString('nothing in this package rasterises vectors', $error->getMessage());
        }
    }

    public function testPassThroughRejectsUnknownGeometryReencodingAndRealOperations(): void
    {
        $driver = new NegotiationDriver();
        $outputs = [
            Output::new()->with(transform: Transform::new()->with(new Escape(static fn(mixed $handle): mixed => $handle))),
            Output::new()->with(encoding: new Encoding(Format::Webp, quality: 80)),
            Output::new()->with(transform: Transform::new()->with(new Rotate(90))),
            Output::new()->with(transform: Transform::new()->with(new Resize(10, 10))),
            Output::new()->with(transform: Transform::new()->with(new Orient())),
        ];

        foreach ($outputs as $index => $output) {
            $plan = Plan::negotiate($this->png(), [$output], candidates: [$driver]);
            self::assertSame($index >= 3, $plan->isPassThrough(0));
        }
    }

    public function testPassThroughBakesAStoredExifOrientationIntoPixels(): void
    {
        $plan = Plan::negotiate(
            Source::file(Corpus::shared()->path('orientation/6.jpg')),
            [Output::new()->with(encoding: new Encoding(metadata: MetadataPolicy::Keep))],
            candidates: [new NegotiationDriver()],
        );

        self::assertFalse($plan->isPassThrough(0));
    }

    private function png(): Source
    {
        $ihdr = pack('NN', 10, 10) . "\x08\x02\x00\x00\x00";

        return Source::bytes(
            "\x89PNG\x0D\x0A\x1A\x0A" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82",
            'fixture',
        );
    }
}

final readonly class NegotiationDriver implements DriverInterface
{
    public function __construct(
        private Support $decode = Support::Exact,
        private Support $encode = Support::Exact,
        private Support $operation = Support::Exact,
    ) {}

    public function name(): string
    {
        return 'fixture';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities('fixture', '1', Format::cases(), Format::cases(), [OperationInterface::class => $this->operation]);
    }

    public function supports(OperationInterface $operation): Support
    {
        return $this->operation;
    }

    public function canDecode(Format $format): Support
    {
        return $this->decode;
    }

    public function canEncode(Encoding $encoding): Support
    {
        return $this->encode;
    }

    public function process(Plan $plan): array
    {
        return [new Result($plan->output(0), '')];
    }
}
