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

use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Driver\Imagick\ResourcePolicy;
use Alto\Image\Exception\CorruptImageException;
use Alto\Image\Exception\LimitExceededException;
use Alto\Image\Image;
use Alto\Image\Limits;
use Alto\Image\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImagickDriver::class)]
#[CoversClass(ResourcePolicy::class)]
final class AnimationLimitsTest extends TestCase
{
    public function testCopyWithUnlimitedPolicyDoesNotChangePixelResourceLimits(): void
    {
        $bytes = $this->sequence('gif');
        $before = \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
        $result = Image::open(Source::bytes($bytes))->using(new ImagickDriver())->within(Limits::none())->keepMetadata()->render();
        self::assertSame($bytes, $result->bytes);
        self::assertSame($before, \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY));
    }

    public function testInvalidPixelPayloadKeepsTheCorruptImageError(): void
    {
        $png = $this->sequence('png');
        $offset = strpos($png, 'IDAT');
        self::assertNotFalse($offset);
        $length = unpack('Nlength', substr($png, $offset - 4, 4));
        self::assertIsArray($length);
        self::assertIsInt($length['length']);
        $bad = str_repeat('x', $length['length']);
        $png = substr_replace($png, $bad . pack('N', crc32('IDAT' . $bad)), $offset + 4, $length['length'] + 4);
        $this->expectException(CorruptImageException::class);
        Image::open(Source::bytes($png))->using(new ImagickDriver())->grayscale()->render();
    }

    #[DataProvider('formats')]
    public function testActualFrameCountCannotExceedTheLimit(string $format): void
    {
        $bytes = $this->sequence($format);
        $request = Image::open(Source::bytes($bytes))->using(new ImagickDriver())->within(new Limits(maxFrames: 2));
        $this->expectException(LimitExceededException::class);
        $request->grayscale()->png()->render();
    }

    public function testAllowedAnimationKeepsItsFramesAndTiming(): void
    {
        $bytes = $this->sequence('gif');
        $output = Image::open(Source::bytes($bytes))->using(new ImagickDriver())->within(new Limits(maxFrames: 3))->grayscale()->render();
        $decoded = new \Imagick();
        $decoded->readImageBlob($output->bytes);
        self::assertSame(3, $decoded->getNumberImages());
        foreach ($decoded as $frame) {
            self::assertSame(10, $frame->getImageDelay());
        }
        $decoded->clear();
    }

    #[DataProvider('frameLimits')]
    public function testFailedAndSuccessfulRequestsRestoreTheNativeFrameLimit(int $maximum): void
    {
        $bytes = $this->sequence('gif');
        if (!defined(\Imagick::class . '::RESOURCETYPE_LISTLENGTH')) {
            self::markTestSkipped('Native list-length control is not available.');
        }
        $previous = \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_LISTLENGTH);
        $image = Image::open(Source::bytes($bytes))->using(new ImagickDriver());
        try {
            $image->within(new Limits(maxFrames: $maximum))->render();
            self::assertSame(3, $maximum);
        } catch (LimitExceededException) {
            self::assertSame(2, $maximum);
        }
        self::assertSame($previous, \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_LISTLENGTH));
        $direct = new \Imagick();
        $direct->readImageBlob($bytes);
        self::assertSame(3, $direct->getNumberImages());
        $direct->clear();
    }

    /**
     * @return iterable<array{int}>
     */
    public static function frameLimits(): iterable
    {
        yield [2];
        yield [3];
    }

    /**
     * @return iterable<array{string}>
     */
    public static function formats(): iterable
    {
        yield ['gif'];
        yield ['webp'];
        yield ['tiff'];
    }

    private function sequence(string $format): string
    {
        if (!extension_loaded('imagick') || [] === \Imagick::queryFormats(strtoupper($format))) {
            self::markTestSkipped('Imagick format not available.');
        }
        $sequence = new \Imagick();
        foreach (['red', 'green', 'blue'] as $colour) {
            $frame = new \Imagick();
            $frame->newImage(2, 2, new \ImagickPixel($colour));
            $frame->setImageFormat($format);
            $frame->setImageDelay(10);
            $sequence->addImage($frame);
            $frame->clear();
        }
        $sequence->setImageFormat($format);
        $bytes = $sequence->getImagesBlob();
        $sequence->clear();
        return $bytes;
    }
}
