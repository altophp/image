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

namespace Alto\Image\Test;

/**
 * Provides a compact Display P3 profile for conformance fixtures.
 *
 * @internal
 *
 * Source: saucecontrol/Compact-ICC-Profiles, DisplayP3-v2-micro.icc
 * License: CC0-1.0
 * SHA-256: cdb9ed06df5cc3be0c24f097407a490daee8ffce764fa8ff1dd66c8b5a591eb7
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class IccProfile
{
    private const string DISPLAY_P3 = 'AAAByGxjbXMCEAAAbW50clJHQiBYWVogB+IAAwAUAAkADgAdYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbWAAEAAAAA0y1oYW5ktKrdHxPIAzz1URRFKHqY4gAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAJZGVzYwAAAPAAAABeY3BydAAAAQwAAAAMd3RwdAAAARgAAAAUclhZWgAAASwAAAAUZ1hZWgAAAUAAAAAUYlhZWgAAAVQAAAAUclRSQwAAAWgAAABgZ1RSQwAAAWgAAABgYlRSQwAAAWgAAABgZGVzYwAAAAAAAAAEdVAzAAAAAAAAAAAAAAAAAHRleHQAAAAAQ0MwAFhZWiAAAAAAAADzUQABAAAAARbMWFlaIAAAAAAAAIPfAAA9v////7tYWVogAAAAAAAASr8AALE3AAAKuVhZWiAAAAAAAAAoOAAAEQoAAMi5Y3VydgAAAAAAAAAqAAAAfAD4AZwCdQODBMkGTggSChgMYg70Ec8U9hhqHC4gQySsKWoufjPrObM/1kZXTTZUdlwXZB1shnVWfo2ILJI2nKunjLLbvpnKx9dl5Hfx+f//';

    public static function displayP3(): string
    {
        $profile = base64_decode(self::DISPLAY_P3, true);

        // @codeCoverageIgnoreStart
        if (false === $profile) {
            throw new \RuntimeException('The embedded Display P3 profile is not valid base64.');
        }
        // @codeCoverageIgnoreEnd

        return $profile;
    }
}
