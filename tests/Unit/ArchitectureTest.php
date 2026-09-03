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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The seam, enforced.
 *
 * Reading `use` statements is enough for every rule here, because this package
 * has no dynamic class resolution: there is no container, no service locator,
 * and no plugin discovery. Every dependency a file has, it names at the top.
 */
final class ArchitectureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function rules(): iterable
    {
        yield 'the pure layer never touches a driver' => [
            'src/Operation',
            ['Alto\Image\Driver'],
            'an operation that knows about a driver cannot be projected without one, '
            . 'and projecting without one is the whole reason size() answers from a header',
        ];

        yield 'stores and analyzers work through the seam' => [
            'src/Store',
            ['Alto\Image\Driver\Gd', 'Alto\Image\Driver\Imagick'],
            'a store that names a concrete driver stops working the moment somebody writes a third',
        ];

        yield 'analyzers are pure functions over bytes' => [
            'src/Analyzer',
            ['Alto\Image\Driver\Gd', 'Alto\Image\Driver\Imagick', 'Imagick', 'GdImage'],
            'an analyzer receives a decoded buffer and nothing else, which is what makes it '
            . 'testable with no extension installed',
        ];

        yield 'the GD driver is not the Imagick driver' => [
            'src/Driver/Gd',
            ['Alto\Image\Driver\Imagick', 'Imagick'],
            'two implementations that share code are one implementation with a bug in the middle',
        ];

        yield 'the Imagick driver is not the GD driver' => [
            'src/Driver/Imagick',
            ['Alto\Image\Driver\Gd'],
            'two implementations that share code are one implementation with a bug in the middle',
        ];

        yield 'internals stay independent from integrations' => [
            'src/Internal',
            ['Alto\Image\Driver\Gd', 'Alto\Image\Driver\Imagick', 'Alto\Image\Store', 'Alto\Image\Test'],
            'internal implementation may orchestrate driver contracts, but never a concrete integration',
        ];

        yield 'the runtime carries no test dependency' => [
            'src/Operation',
            ['PHPUnit'],
            'a test dependency named in the runtime is a production dependency',
        ];

        yield 'exceptions can be thrown before anything is built' => [
            'src/Exception',
            ['Alto\Image\Driver', 'Alto\Image\Store', 'Alto\Image\Analyzer', 'Alto\Image\Operation'],
            'an exception that needs half the package to be constructed cannot be thrown early, '
            . 'and early is when a limit fires',
        ];
    }

    /**
     * @param list<string> $forbidden
     */
    #[DataProvider('rules')]
    public function testTheSeamHolds(string $directory, array $forbidden, string $because): void
    {
        $offences = [];

        foreach (self::imports($directory) as $file => $imports) {
            foreach ($imports as $import) {
                foreach ($forbidden as $namespace) {
                    if ($import === $namespace || str_starts_with($import, $namespace . '\\')) {
                        $offences[] = \sprintf('%s imports %s', $file, $import);
                    }
                }
            }
        }

        self::assertSame([], $offences, \sprintf(
            "%s\n  %s",
            $because,
            implode("\n  ", $offences),
        ));
    }

    /**
     * The one rule that is about the whole package rather than one directory.
     */
    public function testNothingOutsideTheDriversNamesAnImageExtension(): void
    {
        $allowed = ['src/Driver/Gd', 'src/Driver/Imagick', 'src/Test/Corpus.php', 'src/Analyzer/Raster.php'];
        $offences = [];

        foreach (self::files('src') as $path) {
            $relative = self::relative($path);

            foreach ($allowed as $exception) {
                if (str_starts_with($relative, $exception)) {
                    continue 2;
                }
            }

            $source = (string) file_get_contents($path);

            foreach (['imagecreate', 'imagescale', 'imagejpeg', 'imagewebp', 'new \\Imagick', 'Imagick::'] as $call) {
                if (str_contains($source, $call)) {
                    $offences[] = \sprintf('%s calls %s', $relative, $call);
                }
            }
        }

        self::assertSame([], $offences, \sprintf(
            "Only a driver may call an image extension, and Corpus and Raster, which are the two\n"
            . "places that deliberately produce and consume raw pixels.\n  %s",
            implode("\n  ", $offences),
        ));
    }

    /**
     * The surface is tiered, and the tiers have different promises.
     *
     * Every source type belongs to a named promise or marks itself internal.
     */
    public function testTheSurfaceIsStillTiered(): void
    {
        $core = [
            'Image', 'ImageSet', 'Source', 'Result', 'Metadata', 'Size', 'Limits', 'Transform',
            'Format', 'Fit', 'Scaling', 'Anchor', 'Focus', 'FocalPoint', 'Colour',
            'Effort', 'MetadataPolicy', 'FailOn',
        ];

        $extension = [
            'Driver/Support',
            'Operation/OperationInterface', 'Operation/PortableOperationInterface', 'Operation/Solvable', 'Operation/Adjust', 'Operation/Blur',
            'Operation/Crop', 'Operation/Escape', 'Operation/Extend', 'Operation/Flatten', 'Operation/Flip',
            'Operation/Grayscale', 'Operation/IccConvert', 'Operation/Invert', 'Operation/Orient',
            'Operation/Overlay', 'Operation/Pixelate', 'Operation/Placement', 'Operation/Resize', 'Operation/Rotate',
            'Operation/Sharpen', 'Operation/Tint', 'Operation/Trim',
            'Driver/DriverInterface', 'Driver/Capabilities',
            'Analyzer/AnalyzerInterface', 'Analyzer/Raster',
            'Store/StoreInterface', 'Driver/Encoding', 'Driver/Plan', 'Driver/Output',
        ];

        $integrations = [
            'Analyzer/DominantColors', 'Analyzer/PerceptualHash',
            'Driver/Gd/GdDriver', 'Driver/Imagick/ImagickDriver',
            'Store/LocalStore', 'Store/FlysystemStore',
        ];

        $testing = [
            'Test/ArrayDriver', 'Test/Corpus',
            'Test/DriverTestCase', 'Test/ImageAssertions',
        ];

        $exceptions = [
            'Exception/ImageExceptionInterface', 'Exception/CorruptImageException',
            'Exception/DriverException', 'Exception/InvalidArgumentException',
            'Exception/LimitExceededException', 'Exception/SourceNotFoundException',
            'Exception/StoreException', 'Exception/UnmeasurableException',
            'Exception/UnsupportedOperationException',
        ];

        self::assertCount(18, $core, 'The core surface changed. Review the permanent promises.');
        self::assertCount(31, $extension, 'The extension surface changed. Review the driver and operation contracts.');
        self::assertCount(6, $integrations, 'The built-in integrations changed.');
        self::assertCount(4, $testing, 'The testing toolkit changed.');
        self::assertCount(9, $exceptions, 'The exception hierarchy changed.');

        $promised = [...$core, ...$extension, ...$integrations, ...$testing, ...$exceptions];

        foreach ($promised as $type) {
            $path = \dirname(__DIR__, 2) . '/src/' . $type . '.php';

            self::assertFileExists($path, \sprintf('%s is part of the promised surface and is not there.', $type));
            self::assertFalse(
                self::isInternal($path),
                \sprintf('%s is a promise and marks itself @internal.', $type),
            );
        }

        foreach (self::files('src') as $path) {
            $name = substr(self::relative($path), 4, -4);

            if (\in_array($name, $promised, true)) {
                continue;
            }

            self::assertTrue(
                self::isInternal($path),
                \sprintf('%s is in neither promised tier and does not say @internal.', $name),
            );
        }
    }

    public function testEverySourceTypeHasAShortDescription(): void
    {
        $offences = [];

        foreach (self::files('src') as $path) {
            $docblock = self::typeDocblock($path);

            if (null === $docblock) {
                $offences[] = self::relative($path) . ' has no type docblock';

                continue;
            }

            $description = self::docblockDescription($docblock);

            if ('' === $description) {
                $offences[] = self::relative($path) . ' has no type description';
            } elseif (160 < \strlen($description)) {
                $offences[] = \sprintf('%s has a %d-character type description', self::relative($path), \strlen($description));
            } elseif (!str_ends_with($description, '.')) {
                $offences[] = self::relative($path) . ' has an incomplete type description';
            }
        }

        self::assertSame([], $offences, implode("\n", $offences));
    }

    public function testEverySourceTypeHasAMirroredTestClass(): void
    {
        $offences = [];

        foreach (self::files('src') as $source) {
            $relativeType = substr(self::relative($source), 4, -4);
            $test = \dirname(__DIR__, 2) . '/tests/' . $relativeType . 'Test.php';
            $testClass = 'Alto\\Image\\Tests\\' . str_replace('/', '\\', $relativeType) . 'Test';

            if (!is_file($test)) {
                $offences[] = self::relative($source) . ' has no ' . $relativeType . 'Test.php';

                continue;
            }

            if (!class_exists($testClass)) {
                $offences[] = self::relative($test) . ' does not declare ' . $testClass;

                continue;
            }

            $reflection = new \ReflectionClass($testClass);

            if ($reflection->getFileName() !== $test) {
                $offences[] = self::relative($test) . ' does not own ' . $testClass;
            }

            if (!$reflection->isSubclassOf(\Alto\Image\Tests\Support\SourceClassTestCase::class)) {
                $offences[] = $testClass . ' must extend SourceClassTestCase';
            }
        }

        self::assertSame([], $offences, implode("\n", $offences));
    }

    public function testThePromisedSurfaceDoesNotExposeInternalTypes(): void
    {
        $offences = [];

        foreach (self::files('src') as $path) {
            if (self::isInternal($path)) {
                continue;
            }

            $name = substr(self::relative($path), 4, -4);
            $type = 'Alto\\Image\\' . str_replace('/', '\\', $name);

            if (!class_exists($type) && !interface_exists($type) && !trait_exists($type)) {
                $offences[] = \sprintf('%s does not declare the expected type %s', self::relative($path), $type);

                continue;
            }

            $reflection = new \ReflectionClass($type);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $type) {
                    continue;
                }

                foreach ([$method->getReturnType(), ...array_map(static fn(\ReflectionParameter $parameter): ?\ReflectionType => $parameter->getType(), $method->getParameters())] as $reflectionType) {
                    foreach (self::namedTypes($reflectionType) as $namedType) {
                        if (self::typeIsInternal($namedType)) {
                            $offences[] = \sprintf('%s::%s() exposes %s', $type, $method->getName(), $namedType);
                        }
                    }
                }

                foreach ($method->getParameters() as $parameter) {
                    if ($parameter->isDefaultValueAvailable() && $parameter->isDefaultValueConstant()) {
                        $constant = (string) $parameter->getDefaultValueConstantName();
                        $owner = str_contains($constant, '::') ? strstr($constant, '::', true) : false;

                        if (false !== $owner && self::typeIsInternal($owner)) {
                            $offences[] = \sprintf('%s::%s() defaults through %s', $type, $method->getName(), $constant);
                        }
                    }
                }
            }

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $type) {
                    continue;
                }

                foreach (self::namedTypes($property->getType()) as $namedType) {
                    if (self::typeIsInternal($namedType)) {
                        $offences[] = \sprintf('%s::$%s exposes %s', $type, $property->getName(), $namedType);
                    }
                }
            }
        }

        self::assertSame([], $offences);
    }

    private static function typeIsInternal(string $type): bool
    {
        if (!class_exists($type) && !interface_exists($type) && !trait_exists($type) && !enum_exists($type)) {
            return false;
        }

        $path = (new \ReflectionClass($type))->getFileName();

        return false !== $path && self::isInternal($path);
    }

    /**
     * @return list<string>
     */
    private static function namedTypes(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return array_merge(...array_map(self::namedTypes(...), $type->getTypes()));
        }

        return [];
    }

    /**
     * Whether the type declared in this file is marked internal.
     *
     * The class docblock only, and not the whole file, so method annotations do
     * not accidentally classify their declaring type.
     */
    private static function isInternal(string $path): bool
    {
        return str_contains(self::typeDocblock($path) ?? '', '@internal');
    }

    private static function typeDocblock(string $path): ?string
    {
        $source = (string) file_get_contents($path);

        if (1 !== preg_match('/(\/\*\*(?:[^*]|\*(?!\/))*\*\/)\s*(?:(?:final|readonly|abstract)\s+)*(?:class|interface|enum|trait)\s/', $source, $found)) {
            return null;
        }

        return $found[1];
    }

    private static function docblockDescription(string $docblock): string
    {
        $description = [];

        foreach (preg_split('/\R/', $docblock) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*\/?\*+\/?\s?/', '', $line));

            if (str_starts_with($line, '@')) {
                break;
            }

            if ('' !== $line) {
                $description[] = $line;
            }
        }

        return implode(' ', $description);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function imports(string $directory): array
    {
        $imports = [];

        foreach (self::files($directory) as $path) {
            $found = [];
            preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;\s]+)/m', (string) file_get_contents($path), $matches);

            foreach ($matches[1] as $import) {
                $found[] = ltrim(explode(' ', $import)[0], '\\');
            }

            $imports[self::relative($path)] = $found;
        }

        return $imports;
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        $root = \dirname(__DIR__, 2) . '/' . $directory;

        if (is_file($root)) {
            return [$root];
        }

        $paths = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    private static function relative(string $path): string
    {
        return str_replace(\dirname(__DIR__, 2) . '/', '', $path);
    }
}
