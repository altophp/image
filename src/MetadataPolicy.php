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
 * Controls which source metadata survives in an encoded output.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum MetadataPolicy: string
{
    /**
     * Remove every profile and every tag.
     */
    case Strip = 'strip';

    /**
     * Keep the colour profile, remove EXIF, IPTC and XMP.
     */
    case ColourProfile = 'profile';

    /**
     * Keep the copyright and author tags, remove everything else.
     */
    case Copyright = 'copyright';

    /**
     * Keep everything the driver can carry across.
     */
    case Keep = 'keep';

    public function keepsProfile(): bool
    {
        return self::ColourProfile === $this || self::Keep === $this;
    }

    public function keepsMetadata(): bool
    {
        return self::Keep === $this || self::Copyright === $this;
    }

    public function keepsEverything(): bool
    {
        return self::Keep === $this;
    }

    /**
     * Projects the metadata guaranteed to remain after applying this policy.
     */
    public function project(Metadata $metadata): Metadata
    {
        return match ($this) {
            self::Strip => $metadata->withoutIcc()->with(hasMetadata: false),
            self::ColourProfile => $metadata->with(hasMetadata: false),
            self::Copyright => $metadata->withoutIcc(),
            self::Keep => $metadata,
        };
    }
}
