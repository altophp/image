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
use Alto\Image\Exception\SourceNotFoundException;
use Alto\Image\Format;
use Alto\Image\Internal\Fingerprint;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Probing goes through head(), never through contents().
 */
#[CoversClass(Source::class)]
#[CoversClass(Fingerprint::class)]
final class SourceTest extends TestCase
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

    public function testAFileIsProbedFromItsHeadAndNeverItsWhole(): void
    {
        // A megabyte of trailing junk after a complete PNG. If probing read the
        // file it would notice; it reads four kilobytes and does not.
        $path = $this->write('big.png', $this->png(1200, 800) . str_repeat("\x00", 1_000_000));
        $source = Source::file($path);

        self::assertSame('1200x800', (string) $source->metadata()->size);
        self::assertSame(1_000_000 + 45, $source->metadata()->bytes);
        self::assertSame(4096, \strlen($source->head()));
    }

    public function testAMissingFileSaysWhichKindOfMissing(): void
    {
        try {
            Source::file($this->directory . '/nothing.png')->metadata();
            self::fail('A missing file was probed.');
        } catch (SourceNotFoundException $refusal) {
            self::assertStringContainsString('No such file', $refusal->getMessage());
        }

        try {
            Source::file($this->directory)->metadata();
            self::fail('A directory was probed.');
        } catch (SourceNotFoundException $refusal) {
            self::assertStringContainsString('is a directory, not an image', $refusal->getMessage());
        }
    }

    public function testMissingFilesAreRefusedByEveryByteReader(): void
    {
        $source = Source::file($this->directory . '/vanished.png');

        foreach ([$source->tail(...), $source->contents(...)] as $read) {
            try {
                $read();
                self::fail('A missing file was read.');
            } catch (SourceNotFoundException $error) {
                self::assertStringContainsString('No such file', $error->getMessage());
            }
        }
    }

    public function testBytesAndStreamsProbeTheSameWayAFileDoes(): void
    {
        $png = $this->png(320, 240);
        $path = $this->write('a.png', $png);

        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $png);

        foreach ([Source::file($path), Source::bytes($png), Source::stream($stream)] as $source) {
            self::assertSame('320x240', (string) $source->metadata()->size);
            self::assertSame(Format::Png, $source->metadata()->format);
        }

        fclose($stream);
    }

    public function testAStreamMustBeAnOpenStreamResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('open stream resource');

        (new \ReflectionMethod(Source::class, 'stream'))->invoke(null, null);
    }

    public function testAnInMemorySourceExistsAndCachesItsReads(): void
    {
        $bytes = $this->png(40, 30);
        $source = Source::bytes($bytes, 'upload');

        self::assertTrue($source->exists());
        self::assertSame($bytes, $source->contents());
        self::assertSame(\strlen($bytes), $source->length());
        self::assertSame($source->signature(), $source->signature());
        self::assertSame($source->metadata(), $source->metadata());
    }

    public function testAStreamIsDrainedOnceForItsTailAndContents(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'head-and-tail');
        $source = Source::stream($stream);

        self::assertSame('tail', $source->tail(4));
        self::assertSame('head-and-tail', $source->contents());
        fclose($stream);
    }

    public function testAStreamCanBeReadDirectly(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'encoded image bytes');
        $source = Source::stream($stream);

        self::assertSame('encoded image bytes', $source->contents());
        self::assertSame('encoded image bytes', $source->contents());
        fclose($stream);
    }

    public function testAClosedBackingStreamIsRefused(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        $source = Source::stream($stream);
        fclose($stream);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has been closed');
        $source->contents();
    }

    public function testAnEmptySourceIsRefusedWithAHint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pass the encoded image bytes, not a path');

        Source::bytes('');
    }

    /**
     * filemtime() has one-second granularity, so a same-size re-upload inside one
     * second is invisible to it and the derivative it produced stays served.
     */
    public function testTheDefaultFingerprintSeesASameSizeRewrite(): void
    {
        $path = $this->write('photo.png', $this->png(800, 600));
        $before = Source::file($path)->signature();

        // Same size, same second, different pixels.
        $this->write('photo.png', $this->png(800, 600, 'second upload'));
        $after = Source::file($path)->signature();

        self::assertNotSame($before, $after, 'A same-size rewrite inside one second went unnoticed.');
    }

    public function testAStatFingerprintStillIdentifiesAVanishedPath(): void
    {
        $path = $this->directory . '/vanished.png';
        $identify = Fingerprint::stat();

        self::assertSame($identify($path), $identify($path));
    }

    public function testTheContentStrategyCannotBeWrongAtAll(): void
    {
        $path = $this->write('photo.png', $this->png(800, 600));
        $before = Source::file($path, Fingerprint::content())->signature();

        $this->write('photo.png', $this->png(800, 600, 'second upload'));

        $after = Source::file($path, Fingerprint::content())->signature();

        self::assertNotSame($before, $after);
        self::assertSame(
            $after,
            Source::file($path)->identifiedBy(Fingerprint::content())->signature(),
            'identifiedBy() has to swap the strategy on a Source that already has one.',
        );
    }

    public function testOnlyFileSourcesCanReplaceTheirFingerprintStrategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a file Source');

        Source::bytes('pixels')->identifiedBy(Fingerprint::content());
    }

    public function testAClosedStreamNoLongerClaimsToExist(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $this->png(1, 1));
        rewind($stream);
        $source = Source::stream($stream);

        self::assertTrue($source->exists());
        fclose($stream);
        self::assertFalse($source->exists());
    }

    public function testTheTailIsReadWithoutReadingTheWhole(): void
    {
        $path = $this->write('photo.png', $this->png(800, 600));

        self::assertStringEndsWith("IEND\xAE\x42\x60\x82", Source::file($path)->tail());
    }

    public function testItNamesItselfForAnErrorMessage(): void
    {
        self::assertSame('photo', Source::file('/var/www/photo.jpg')->name);
        self::assertSame('/var/www/photo.jpg', (string) Source::file('/var/www/photo.jpg'));
        self::assertSame('upload (in memory)', (string) Source::bytes('x', 'upload'));
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
