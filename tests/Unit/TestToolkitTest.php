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

use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Exception\StoreException;
use Alto\Image\Test\Corpus;
use Alto\Image\Test\DriverTestCase;
use Alto\Image\Test\ImageAssertions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Corpus::class)]
#[CoversClass(DriverTestCase::class)]
final class TestToolkitTest extends TestCase
{
    use ImageAssertions;

    private string $directory = '';

    protected function setUp(): void
    {
        if (!GdDriver::isAvailable()) {
            self::markTestSkipped('ext-gd is not installed.');
        }

        $this->directory = sys_get_temp_dir() . '/alto-test-toolkit-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->sweep($this->directory);
    }

    public function testConformanceProvidersCanBeConsumedOutsidePhpunitAttributes(): void
    {
        self::assertCount(37, iterator_to_array(DriverTestCase::transforms()));
        self::assertCount(5, iterator_to_array(DriverTestCase::reductions()));
        self::assertCount(6, iterator_to_array(DriverTestCase::hostileFixtures()));
    }

    public function testFlatnessFailureNamesTheFirstDifferentPixel(): void
    {
        $bytes = (string) file_get_contents(Corpus::shared()->path('edge.png'));

        try {
            self::assertImageIsFlat($bytes, new GdDriver());
            self::fail('A two-colour image was accepted as flat.');
        } catch (AssertionFailedError $error) {
            self::assertStringContainsString('pixel at', $error->getMessage());
        }
    }

    public function testCorpusCreationFailuresNameEachDirectory(): void
    {
        $rootFile = $this->directory . '/root-file';
        file_put_contents($rootFile, 'occupied');

        try {
            (new Corpus($rootFile))->build();
            self::fail('A corpus was built over a file.');
        } catch (StoreException $error) {
            self::assertStringContainsString('create the corpus directory', $error->getMessage());
        }

        foreach (['writeOrientations' => 'orientation', 'writeMalformed' => 'malformed', 'writeBombs' => 'bombs'] as $method => $name) {
            $root = $this->directory . '/' . $name;
            mkdir($root);
            file_put_contents($root . '/' . $name, 'occupied');

            try {
                (new \ReflectionMethod(Corpus::class, $method))->invoke(new Corpus($root));
                self::fail(\sprintf('%s was created over a file.', $name));
            } catch (StoreException $error) {
                self::assertStringContainsString('create the ' . $name . ' directory', $error->getMessage());
            }
        }
    }

    private function sweep(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->sweep($entry) : @unlink($entry);
        }

        @rmdir($directory);
    }
}
