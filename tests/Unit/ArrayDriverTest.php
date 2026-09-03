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
use Alto\Image\Driver\Output;
use Alto\Image\Driver\Support;
use Alto\Image\Format;
use Alto\Image\Image;
use Alto\Image\Operation\Blur;
use Alto\Image\Operation\Escape;
use Alto\Image\Source;
use Alto\Image\Test\ArrayDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayDriver::class)]
final class ArrayDriverTest extends TestCase
{
    public function testItReportsTheCapabilitiesOfAFakeRasterDriver(): void
    {
        $driver = new ArrayDriver();

        self::assertSame('array', $driver->name());
        self::assertTrue($driver->capabilities()->canRead(Format::Jpeg));
        self::assertFalse($driver->capabilities()->canWrite(Format::Svg));
        self::assertSame(Support::Exact, $driver->supports(new Blur()));
        self::assertSame(Support::No, $driver->supports(new Escape(static fn(mixed $handle): mixed => $handle)));
        self::assertSame(Support::Approximate, $driver->canDecode(Format::Svg));
        self::assertSame(Support::Exact, $driver->canDecode(Format::Png));
        self::assertSame(Support::No, $driver->canEncode(new Encoding(Format::Svg)));
        self::assertSame(Support::Exact, $driver->canEncode(new Encoding(Format::Webp)));
    }

    public function testItRecordsBatchesAndReturnsReadableMarkerBytes(): void
    {
        $driver = new ArrayDriver();
        $results = Image::open($this->source())->using($driver)->cover(ratio: 16 / 9)->widths(320, 640)->webp()->render();

        self::assertSame(1, $driver->batches());
        self::assertSame(['320x180', '640x360'], $driver->outputs());
        self::assertCount(2, $driver->calls());
        self::assertStringStartsWith("alto:fake\nphoto (in memory)\n", $results[0]->bytes);

        $driver->forget();

        self::assertSame([], $driver->calls());
        self::assertSame(0, $driver->batches());
    }

    public function testCannedBytesOverrideTheMarkerForAKnownOutput(): void
    {
        $output = Output::new()->with(encoding: new Encoding(Format::Webp));
        $driver = new ArrayDriver([$output->signature() => 'canned']);

        $result = Image::open($this->source())->using($driver)->webp()->render();

        self::assertSame('canned', $result->bytes);
        self::assertSame(6, $result->metadata->bytes);
    }

    private function source(): Source
    {
        $ihdr = pack('NN', 1200, 800) . "\x08\x02\x00\x00\x00";

        return Source::bytes(
            "\x89PNG\x0D\x0A\x1A\x0A" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82",
            'photo',
        );
    }
}
