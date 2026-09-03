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

namespace Alto\Image;

/**
 * Controls which decoder warnings make an image fail.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum FailOn: string
{
    /**
     * Never fail on a warning. Decode whatever bytes parsed.
     */
    case None = 'none';

    /**
     * Fail when the image is truncated past the point of being usable.
     */
    case Truncated = 'truncated';

    /**
     * Fail on any decoder warning at all.
     */
    case Warning = 'warning';

    /**
     * Fail on decoder errors, while tolerating ordinary warnings.
     */
    case Error = 'error';

    /**
     * Whether this policy refuses a decode that produced this warning.
     *
     * Applies one rule to the different warning strings emitted by GD and
     * ImageMagick. Drivers report decoder warnings without interpreting them.
     */
    public function rejects(string $warning): bool
    {
        return match ($this) {
            self::None => false,
            self::Truncated => 1 === preg_match(
                '/premature|truncat|corrupt|unrecoverable|not enough|insufficient|unexpected end/i',
                $warning,
            ),
            self::Warning => true,
            self::Error => 1 === preg_match('/error|fatal|failed|corrupt|unrecoverable/i', $warning),
        };
    }
}
