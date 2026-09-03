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
 * Reports work that no candidate image driver can perform.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class UnsupportedOperationException extends \RuntimeException implements ImageExceptionInterface
{
    /**
     * @param list<string> $refusals one line per driver, already formatted
     */
    public static function noDriverFor(string $what, array $refusals, string $remedy): self
    {
        $message = \sprintf('No installed driver can %s.', $what);

        foreach ($refusals as $refusal) {
            $message .= "\n  - " . $refusal;
        }

        return new self($message . "\n  Try: " . $remedy);
    }
}
