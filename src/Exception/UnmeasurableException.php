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
 * Reports an image property that cannot be projected without decoding pixels.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class UnmeasurableException extends \LogicException implements ImageExceptionInterface
{
    public static function trimmed(string $method, string $transform): self
    {
        return new self(\sprintf(
            "%s reads the header and never decodes, but \"%s\" starts with a trim, whose output only the pixels know.\n"
            . "  A later resize makes the answer knowable again, but only one that pins both axes down and permits any scale.\n"
            . '  Try: append a fixed box, as in "%s|cover=800x450,s:both", or save() the Image and re-open the result.',
            $method,
            $transform,
            $transform,
        ));
    }

    public static function escaped(string $method): self
    {
        return new self(\sprintf(
            "%s reads the header and never decodes, but this Image holds an Escape, which runs arbitrary code on the raw handle.\n"
            . "  Nothing above the driver can determine what that code produces.\n"
            . '  Try: drop the Escape, or save() the Image and re-open the result.',
            $method,
        ));
    }
}
