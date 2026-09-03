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

namespace Alto\Image\Tests\Fuzz;

use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Exception\ImageExceptionInterface;
use Alto\Image\FailOn;
use Alto\Image\Image;
use Alto\Image\Limits;
use Alto\Image\Source;
use Alto\Image\Test\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Malformed and hostile input must throw, never fatal.
 *
 * The bar is deliberately low and absolutely firm: whatever the bytes are, the
 * process survives and the caller gets something catchable that implements
 * ImageExceptionInterface. A segfault is an outage, and a silent image of
 * nothing is worse than an outage because it ships.
 */
final class MalformedCorpusTest extends TestCase
{
    private static ?Corpus $corpus = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (array_keys(self::corpus()->hostile()) as $label) {
            yield $label => [$label];
        }
    }

    /**
     * Probing either refuses or returns something sane. It never guesses.
     *
     * Several of these probe perfectly well and should: a truncated JPEG has an
     * intact header, a pixel bomb's header is honest and enormous, and a PNG cut
     * off after its IHDR has said everything a header read asks for. What the
     * probe must not do is invent a size, and what must not happen afterwards is
     * a decoder being handed any of them. That is the next test.
     */
    #[DataProvider('fixtures')]
    public function testProbingHostileBytesNeverGuesses(string $label): void
    {
        try {
            $metadata = Source::file(self::corpus()->hostile()[$label])->metadata();

            self::assertGreaterThan(0, $metadata->size->width, \sprintf('"%s" probed as a zero-width image.', $label));
            self::assertGreaterThan(0, $metadata->size->height);
        } catch (ImageExceptionInterface $expected) {
            self::assertNotSame('', $expected->getMessage(), 'An exception with no message is not a fix.');
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fixturesPerDriver(): iterable
    {
        foreach (array_keys(self::corpus()->hostile()) as $label) {
            foreach (['gd', 'imagick'] as $driver) {
                yield $label . ' on ' . $driver => [$label, $driver];
            }
        }
    }

    #[DataProvider('fixturesPerDriver')]
    public function testRenderingHostileBytesThrowsRatherThanFatals(string $label, string $name): void
    {
        $driver = 'gd' === $name ? new GdDriver() : new ImagickDriver();

        if (!$driver->capabilities()->isAvailable()) {
            self::markTestSkipped(\sprintf('%s is not installed here.', $name));
        }

        $this->expectException(ImageExceptionInterface::class);

        Image::open(self::corpus()->hostile()[$label])
            ->using($driver)
            ->within(new Limits(maxPixels: 20_000_000))
            ->cover(64, 64)
            ->png()
            ->render();
    }

    /**
     * The lenient policy is lenient about truncation, not about everything.
     */
    public function testTheLenientPolicyStillRefusesWhatIsNotAnImage(): void
    {
        $this->expectException(ImageExceptionInterface::class);

        Image::open(self::corpus()->hostile()['not an image'])
            ->within(new Limits(failOn: FailOn::None))
            ->cover(64, 64)
            ->png()
            ->render();
    }

    /**
     * A bomb is refused from its header, so no decoder is ever handed one. On a
     * PHP linked against an external libgd this check is the only thing between
     * the process and fourteen gigabytes of malloc.
     */
    public function testABombNeverReachesADecoder(): void
    {
        $before = memory_get_peak_usage(true);

        try {
            Image::open(self::corpus()->hostile()['pixel bomb'])->cover(64, 64)->png()->render();
            self::fail('A 3.6 billion pixel image was handed to a decoder.');
        } catch (ImageExceptionInterface $refused) {
            self::assertStringContainsString('limit', $refused->getMessage());
            self::assertStringContainsString('60000x60000', $refused->getMessage());
        }

        self::assertLessThan(
            8 * 1024 * 1024,
            memory_get_peak_usage(true) - $before,
            'Refusing a bomb allocated megabytes, which means something decoded first.',
        );
    }

    /**
     * Fuzzing the EXIF parser directly, because that is the one place this
     * package parses a hostile binary format by hand.
     */
    public function testTheExifParserSurvivesRandomBytes(): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(20260822));

        for ($i = 0; $i < 500; ++$i) {
            $payload = $random->getBytes($random->getInt(1, 512));
            $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', $random->getInt(0, 65535)) . "Exif\x00\x00" . $payload;

            $orientation = \Alto\Image\Internal\ExifReader::orientation($jpeg);

            self::assertGreaterThanOrEqual(1, $orientation);
            self::assertLessThanOrEqual(8, $orientation);
        }
    }

    /**
     * And the header reader, which is the other one.
     */
    public function testTheHeaderReaderSurvivesRandomBytes(): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(20260822));
        $signatures = ["\x89PNG\x0D\x0A\x1A\x0A", "\xFF\xD8\xFF", 'RIFF', "\x00\x00\x00\x18ftyp", "\xFF\x0A", '<svg ', 'GIF89a', 'BM'];

        $read = 0;

        for ($i = 0; $i < 1_000; ++$i) {
            $bytes = $signatures[$random->getInt(0, \count($signatures) - 1)] . $random->getBytes($random->getInt(1, 256));

            $metadata = \Alto\Image\Internal\HeaderReader::tryRead($bytes);

            if (null !== $metadata) {
                ++$read;
                self::assertGreaterThan(0, $metadata->size->width, 'A header read produced a zero-width image.');
                self::assertGreaterThan(0, $metadata->size->height);
            }
        }

        // Surviving is the assertion; the count is here so that a reader can see
        // the fuzzer is producing something occasionally parseable rather than
        // a thousand rejects, which would make the loop vacuous.
        self::assertGreaterThan(0, $read, 'A thousand random headers produced nothing readable at all.');
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= Corpus::shared();
    }
}
