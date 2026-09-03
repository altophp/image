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

use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Internal\Fingerprint;
use Alto\Image\Metadata;

/**
 * Converts an image to a named ICC colour profile.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class IccConvert implements PortableOperationInterface
{
    /**
     * @param string $profile "srgb", "gray", or a path to an .icc file
     */
    public function __construct(public string $profile = 'srgb')
    {
        if ('' === trim($profile)) {
            throw new InvalidArgumentException('IccConvert needs a profile name or a path to an .icc file.');
        }
    }

    public function project(Metadata $input): Metadata
    {
        return $input->with(
            colourSpace: match (strtolower($this->profile)) {
                'srgb' => Metadata::SRGB,
                'gray', 'grey' => Metadata::GRAY,
                'cmyk' => Metadata::CMYK,
                default => $input->colourSpace,
            },
            icc: $this->profile,
        );
    }

    public function __toString(): string
    {
        return 'icc=' . rawurlencode($this->profile);
    }

    /**
     * A named profile is its own identity; a profile file follows source-style stat fingerprinting.
     */
    public function dependencySignature(): string
    {
        return is_file($this->profile)
            ? (Fingerprint::stat())($this->profile)
            : hash('xxh128', strtolower($this->profile));
    }

    public static function parse(array $arguments): static
    {
        return new self(rawurldecode($arguments['0'] ?? 'srgb'));
    }
}
