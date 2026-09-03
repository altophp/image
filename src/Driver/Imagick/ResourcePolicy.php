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

namespace Alto\Image\Driver\Imagick;

use Alto\Image\Limits;

/**
 * Applies and verifies ImageMagick resource limits.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ResourcePolicy
{
    /**
     * How many copies of the source a pipeline holds at once.
     *
     * The decoded source, one intermediate, and the output. Budgeting for fewer
     * is how a limit that was meant to bound ImageMagick instead pushes it onto
     * the disk cache, which is slower than the work it was avoiding and fails
     * outright when the temp directory is small.
     */
    private const int WORKING_COPIES = 3;

    /**
     * @return array<string, array{asked: int, got: int}> what moved and what did not
     */
    public static function apply(Limits $limits): array
    {
        if (!\extension_loaded('imagick')) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        $budget = $limits->maxPixels * self::bytesPerPixel() * self::WORKING_COPIES;

        $asked = [
            'width' => $limits->maxDimension,
            'height' => $limits->maxDimension,
            'area' => $budget,
            'memory' => $budget,
            'map' => $budget * 2,
        ];

        $applied = [];

        foreach ($asked as $name => $value) {
            $constant = self::constant($name);

            if (null === $constant) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            @\Imagick::setResourceLimit($constant, $value);
            $got = @\Imagick::getResourceLimit($constant);
            $applied[$name] = ['asked' => $value, 'got' => \is_int($got) || \is_float($got) ? (int) $got : $value];
        }

        return $applied;
    }

    /**
     * The limits that did not end up where they were asked to, for doctor.
     *
     * @param array<string, array{asked: int, got: int}> $applied
     *
     * @return list<string>
     */
    public static function overridden(array $applied): array
    {
        $notes = [];

        foreach ($applied as $name => ['asked' => $asked, 'got' => $got]) {
            if ($got < $asked) {
                $notes[] = \sprintf(
                    'RESOURCETYPE_%s asked for %s and got %s: policy.xml is lower and wins.',
                    strtoupper($name),
                    number_format($asked),
                    number_format($got),
                );
            }
        }

        return $notes;
    }

    /**
     * The bytes used by one pixel for this quantum depth and HDRI mode.
     */
    private static function bytesPerPixel(): int
    {
        $depth = 16;
        $hdri = false;

        try {
            $reported = \Imagick::getQuantumDepth()['quantumDepthLong'] ?? 16;
            $depth = \is_int($reported) ? $reported : 16;
            $version = \Imagick::getVersion()['versionString'] ?? '';
            $hdri = \is_string($version) && str_contains(strtolower($version), 'hdri');
            // @codeCoverageIgnoreStart
        } catch (\Throwable) {
            // A build that will not answer gets the pessimistic number.
            return 16;
            // @codeCoverageIgnoreEnd
        }

        // Four channels, at the wider of the quantum size and a float when the
        // build carries floats.
        return 4 * max(intdiv($depth, 8), $hdri ? 4 : 1);
    }

    /**
     * The extension declares these as class constants rather than an enum, and
     * an older build may be missing one, so they are looked up by name.
     *
     * @return int<0, 11>|null
     */
    private static function constant(string $name): ?int
    {
        $map = [
            'width' => 'RESOURCETYPE_WIDTH',
            'height' => 'RESOURCETYPE_HEIGHT',
            'area' => 'RESOURCETYPE_AREA',
            'memory' => 'RESOURCETYPE_MEMORY',
            'map' => 'RESOURCETYPE_MAP',
        ];

        $constant = \Imagick::class . '::' . ($map[$name] ?? '');

        if (!\defined($constant)) {
            return null;
        }

        $value = \constant($constant);

        return \is_int($value) && $value >= 0 && $value <= 11 ? $value : null;
    }
}
