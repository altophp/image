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

namespace Alto\Image\Tests\Test;

use Alto\Image\Test\IccProfile;
use Alto\Image\Tests\Support\SourceClassTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class IccProfileTest extends SourceClassTestCase
{
    protected const string SUBJECT = IccProfile::class;

    public function testItProvidesTheDocumentedProfile(): void
    {
        $profile = IccProfile::displayP3();

        self::assertSame(456, \strlen($profile));
        self::assertSame('acsp', substr($profile, 36, 4));
        self::assertSame('cdb9ed06df5cc3be0c24f097407a490daee8ffce764fa8ff1dd66c8b5a591eb7', hash('sha256', $profile));
    }
}
