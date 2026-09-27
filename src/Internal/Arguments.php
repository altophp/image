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

namespace Alto\Image\Internal;

use Alto\Image\Exception\InvalidArgumentException;

/**
 * Validation shared by the built-in operation parsers.
 *
 * @internal
 */
final class Arguments
{
    /**
     * @param array<array-key, string> $arguments
     * @param array<array-key, string> $schema
     */
    public static function check(array $arguments, array $schema): void
    {
        foreach ($arguments as $key => $value) {
            if ('@' === $key) {
                continue;
            }

            $type = $schema[$key] ?? null;

            if (null === $type) {
                throw new InvalidArgumentException(\sprintf('Unknown argument "%s" for %s.', $key, $arguments['@'] ?? 'this operation'));
            }

            $valid = match ($type) {
                'int' => self::integer($value),
                'float' => is_numeric($value) && is_finite((float) $value),
                'box' => self::box($value, true),
                'size' => self::box($value, false),
                default => true,
            };

            if (!$valid) {
                throw new InvalidArgumentException(\sprintf('Invalid %s argument "%s": "%s".', $type, $key, $value));
            }
        }
    }

    private static function integer(string $value): bool
    {
        return 1 === preg_match('/^[+-]?\d+$/D', $value) && false !== filter_var($value, \FILTER_VALIDATE_INT);
    }

    private static function box(string $value, bool $optional): bool
    {
        $axes = explode('x', $value);

        if (\count($axes) > 2 || (!$optional && 2 !== \count($axes))) {
            return false;
        }

        foreach ($axes as $axis) {
            if ('' === $axis && $optional) {
                continue;
            }

            if (!self::integer($axis) || (int) $axis < 1) {
                return false;
            }
        }

        return true;
    }
}
