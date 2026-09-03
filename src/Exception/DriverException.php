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

namespace Alto\Image\Exception;

/**
 * Reports a runtime failure inside an image driver.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class DriverException extends \RuntimeException implements ImageExceptionInterface
{
    public static function failed(string $driver, string $doing, string $why = ''): self
    {
        return new self(rtrim(\sprintf('%s failed while %s. %s', $driver, $doing, $why)));
    }
}
