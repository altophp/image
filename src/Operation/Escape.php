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

namespace Alto\Image\Operation;

use Alto\Image\Exception\UnmeasurableException;

/**
 * Runs a driver-native callback outside the portable transform grammar.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Escape implements OperationInterface
{
    /**
     * @param \Closure(mixed): mixed $handler  receives the driver's native handle and returns one
     * @param string                 $label    what to call this in an error message
     * @param string|null            $identity stable deployment-defined cache identity
     */
    public function __construct(
        public \Closure $handler,
        public string $label = 'escape',
        public ?string $identity = null,
    ) {}

    public function signature(): string
    {
        if (null === $this->identity || '' === trim($this->identity)) {
            throw new UnmeasurableException(\sprintf(
                "Cannot sign the \"%s\" Escape.\n"
                . "  A closure has no stable cache identity.\n"
                . '  Try: pass identity: with a value that changes whenever its behaviour changes.',
                $this->label,
            ));
        }

        return 'escape=' . rawurlencode($this->identity);
    }
}
