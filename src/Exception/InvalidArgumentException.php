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
 * Reports an invalid public API argument.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InvalidArgumentException extends \InvalidArgumentException implements ImageExceptionInterface {}
