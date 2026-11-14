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

use Alto\Image\Driver\Capabilities;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Driver\Encoding;
use Alto\Image\Driver\Output;
use Alto\Image\Driver\Plan;
use Alto\Image\Driver\Support;
use Alto\Image\Format;
use Alto\Image\Operation\OperationInterface;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plan::class)]
final class PlanSelectionTest extends TestCase
{
    public function testAnExactDriverWinsOverAnEarlierApproximation(): void
    {
        $approximate = $this->driver('approximate', Support::Approximate);
        $exact = $this->driver('exact');
        $unused = $this->createMock(DriverInterface::class);
        $unused->expects(self::never())->method('canDecode');

        $plan = Plan::negotiate($this->source(), [Output::new()], candidates: [$approximate, $exact, $unused]);

        self::assertSame($exact, $plan->driver);
        self::assertSame([], $plan->degradations);
    }

    public function testTheFirstApproximationIsRetainedWhenNoExactDriverExists(): void
    {
        $first = $this->driver('first', Support::Approximate, Support::Approximate);
        $second = $this->driver('second', Support::Approximate);
        $output = Output::new()->with(encoding: new Encoding(Format::Webp));

        $plan = Plan::negotiate($this->source(), [$output, $output], candidates: [$first, $second]);

        self::assertSame($first, $plan->driver);
        self::assertSame([
            'first reads png with losses',
            'first writes webp approximately',
        ], $plan->degradations);
    }

    public function testADriverMustAcceptEveryOutputInTheBatch(): void
    {
        $partial = $this->driver(
            'partial',
            encodeCallback: static fn(Encoding $encoding): Support => Format::Avif === $encoding->format ? Support::No : Support::Exact,
        );
        $complete = $this->driver('complete');
        $outputs = [
            Output::new()->with(encoding: new Encoding(Format::Webp)),
            Output::new()->with(encoding: new Encoding(Format::Avif)),
        ];

        $plan = Plan::negotiate($this->source(), $outputs, candidates: [$partial, $complete]);

        self::assertSame($complete, $plan->driver);
        self::assertSame([Format::Webp, Format::Avif], array_map(
            static fn(\Alto\Image\Metadata $metadata): Format => $metadata->format,
            $plan->outputs,
        ));
    }

    private function driver(
        string $name,
        Support $decode = Support::Exact,
        Support $encode = Support::Exact,
        ?\Closure $encodeCallback = null,
    ): DriverInterface&MockObject {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('name')->willReturn($name);
        $driver->method('canDecode')->willReturn($decode);
        null === $encodeCallback
            ? $driver->method('canEncode')->willReturn($encode)
            : $driver->method('canEncode')->willReturnCallback($encodeCallback);
        $driver->method('supports')->willReturn(Support::Exact);
        $driver->method('capabilities')->willReturn(new Capabilities(
            $name,
            'test',
            [Format::Png],
            Format::cases(),
            [OperationInterface::class => Support::Exact],
        ));
        $driver->expects(self::never())->method('process');

        return $driver;
    }

    private function source(): Source
    {
        $header = pack('NN', 120, 80) . "\x08\x02\x00\x00\x00";

        return Source::bytes(
            "\x89PNG\x0D\x0A\x1A\x0A"
            . pack('N', 13) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header))
            . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82",
        );
    }
}
