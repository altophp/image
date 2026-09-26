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
use Alto\Image\Exception\LimitExceededException;
use Alto\Image\Internal\Fingerprint;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Probing goes through head(), never through contents().
 */
#[CoversClass(Source::class)]
#[CoversClass(Fingerprint::class)]
final class SourceLimitsTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/alto-source-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testAStreamReportsItsWholeLengthAndCanBeRetriedAfterALimit(): void
    {
        $bytes = $this->png(120, 80) . str_repeat("\0", 20_000);
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        $source = Source::stream($stream);

        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                try {
                    $source->metadata(8192);
                    self::fail('The stream crossed its byte ceiling.');
                } catch (\Alto\Image\Exception\LimitExceededException) {
                    self::assertSame(8193, ftell($stream), 'A failed read must remain bounded and must not consume more on retry.');
                }
            }

            self::assertSame(\strlen($bytes), $source->metadata(30_000)->bytes);
            self::assertSame($bytes, $source->contents());
        } finally {
            fclose($stream);
        }
    }

    public function testALargeStreamAndTheEquivalentFileHaveTheSameByteCount(): void
    {
        $bytes = $this->png(120, 80) . str_repeat("\0", 20_000);
        $path = $this->write('large.png', $bytes);
        $stream = fopen($path, 'rb');
        self::assertIsResource($stream);

        try {
            self::assertSame(Source::file($path)->metadata()->bytes, Source::stream($stream)->metadata()->bytes);
        } finally {
            fclose($stream);
        }
    }

    public function testPlanningEnforcesTheStreamLimitBeforeCallingADriver(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $this->png(120, 80) . str_repeat("\0", 20_000));
        $image = \Alto\Image\Image::open(Source::stream($stream))
            ->within(new \Alto\Image\Limits(maxBytes: 8192))
            ->using(new \Alto\Image\Tests\Support\ArrayDriver());

        try {
            $this->expectException(\Alto\Image\Exception\LimitExceededException::class);
            $image->bytes();
        } finally {
            self::assertSame(8193, ftell($stream));
            fclose($stream);
        }
    }

    public function testACachedProbeStillHonoursAStricterByteCeiling(): void
    {
        $bytes = $this->png(16, 8);
        $source = Source::bytes($bytes);
        $metadata = $source->metadata();

        self::assertSame($metadata, $source->metadata(\strlen($bytes)));
        $this->expectException(LimitExceededException::class);

        $source->metadata(\strlen($bytes) - 1);
    }

    public function testANonSeekableStreamCanResumeAfterHittingTheByteCeiling(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$writer, $reader] = $pair;
        $bytes = $this->png(16, 8);

        try {
            self::assertSame(\strlen($bytes), fwrite($writer, $bytes));
            self::assertTrue(stream_socket_shutdown($writer, \STREAM_SHUT_WR));
            self::assertFalse(stream_get_meta_data($reader)['seekable']);
            $source = Source::stream($reader);

            for ($attempt = 0; $attempt < 2; ++$attempt) {
                try {
                    $source->metadata(8);
                    self::fail('An oversized non-seekable stream was accepted.');
                } catch (LimitExceededException) {
                    self::assertNull($source->length());
                }
            }

            self::assertSame(\strlen($bytes), $source->metadata(\strlen($bytes))->bytes);
            self::assertSame($bytes, $source->contents());
        } finally {
            fclose($writer);
            fclose($reader);
        }
    }

    public function testANonBlockingStreamWithoutDataFailsInsteadOfSpinning(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$writer, $reader] = $pair;

        try {
            self::assertTrue(stream_set_blocking($reader, false));
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Could not read the stream');

            Source::stream($reader)->contents();
        } finally {
            fclose($writer);
            fclose($reader);
        }
    }

    public function testStoreKeysEnforceTheStreamLimitBeforeHashing(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $this->png(120, 80) . str_repeat("\0", 20_000));
        $image = \Alto\Image\Image::open(Source::stream($stream))
            ->within(new \Alto\Image\Limits(maxBytes: 8192))
            ->using(new \Alto\Image\Tests\Support\ArrayDriver());

        try {
            $this->expectException(\Alto\Image\Exception\LimitExceededException::class);
            $image->store($this->directory . '/cache');
        } finally {
            self::assertSame(8193, ftell($stream));
            fclose($stream);
        }
    }

    private function write(string $name, string $bytes): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $bytes);

        return $path;
    }

    private function png(int $width, int $height, string $salt = ''): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";
        $text = '' === $salt ? '' : pack('N', \strlen($salt)) . 'tEXt' . $salt . pack('N', crc32('tEXt' . $salt));

        return "\x89PNG\x0D\x0A\x1A\x0A"
            . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . $text
            . pack('N', 0) . 'IEND' . "\xAE\x42\x60\x82";
    }
}
