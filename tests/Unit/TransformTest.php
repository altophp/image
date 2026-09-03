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

namespace Alto\Image\Tests\Unit;

use Alto\Image\Anchor;
use Alto\Image\Exception\InvalidArgumentException;
use Alto\Image\Format;
use Alto\Image\Metadata;
use Alto\Image\Operation\Adjust;
use Alto\Image\Operation\Blur;
use Alto\Image\Operation\Crop;
use Alto\Image\Operation\Escape;
use Alto\Image\Operation\Extend;
use Alto\Image\Operation\Flatten;
use Alto\Image\Operation\Flip;
use Alto\Image\Operation\Grayscale;
use Alto\Image\Operation\IccConvert;
use Alto\Image\Operation\Invert;
use Alto\Image\Operation\Orient;
use Alto\Image\Operation\Overlay;
use Alto\Image\Operation\Pixelate;
use Alto\Image\Operation\Resize;
use Alto\Image\Operation\Rotate;
use Alto\Image\Operation\Sharpen;
use Alto\Image\Operation\Tint;
use Alto\Image\Operation\Trim;
use Alto\Image\Size;
use Alto\Image\Transform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A transform is a string in both directions, and there is no serialiser class.
 */
#[CoversClass(Transform::class)]
#[CoversClass(Adjust::class)]
#[CoversClass(Blur::class)]
#[CoversClass(Crop::class)]
#[CoversClass(Escape::class)]
#[CoversClass(Extend::class)]
#[CoversClass(Flatten::class)]
#[CoversClass(Flip::class)]
#[CoversClass(Grayscale::class)]
#[CoversClass(IccConvert::class)]
#[CoversClass(Invert::class)]
#[CoversClass(Orient::class)]
#[CoversClass(Overlay::class)]
#[CoversClass(Pixelate::class)]
#[CoversClass(Resize::class)]
#[CoversClass(Rotate::class)]
#[CoversClass(Sharpen::class)]
#[CoversClass(Tint::class)]
#[CoversClass(Trim::class)]
final class TransformTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function canonical(): iterable
    {
        foreach ([
            'cover=800x450',
            'cover=800x450,g:attention',
            'cover=800x450,g:entropy',
            'cover=1280x720,g:top-right,s:both',
            'cover=400x400,g:0.25x0.75',
            'cover=x,r:1.7777777777777777',
            'inside=1200x',
            'inside=x800',
            'outside=800x800,s:up',
            'contain=400x400,s:none,bg:ffffff',
            'contain=400x400,bg:00000080',
            'fill=200x120,s:both',
            'crop=800x450',
            'crop=800x450,x:100,y:50',
            'crop=800x450,g:bottom-left',
            'extend=12',
            'extend=0,t:40,r:20,bg:ffffff',
            'trim=12',
            'trim=8,bg:ffffff',
            'rotate=90',
            'rotate=37.5,bg:222222',
            'flip=h',
            'flip=v',
            'orient',
            'flatten=ffffff',
            'overlay=logo%2Fmark.png,g:top-left,o:0.8,m:12',
            'blur=2.5',
            'sharpen=1,a:1.5',
            'adjust=b:10,c:-5,s:20,g:1.2',
            'adjust',
            'grayscale',
            'invert',
            'pixelate=8',
            'tint=e63946,o:0.5',
            'icc=srgb',
            'orient|cover=1280x720|sharpen=1|blur=2.5',
            'trim=10|cover=800x450,s:both|grayscale',
        ] as $transform) {
            yield $transform => [$transform];
        }
    }

    /**
     * The string form is the promise, so it has to survive a round trip exactly.
     */
    #[DataProvider('canonical')]
    public function testEveryCanonicalFormRoundTrips(string $transform): void
    {
        self::assertSame($transform, (string) Transform::parse($transform));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalised(): iterable
    {
        yield 'a short hex becomes a long one' => ['rotate=90,bg:fff', 'rotate=90,bg:ffffff'];
        yield 'a named colour becomes hex' => ['flatten=white', 'flatten=ffffff'];
        yield 'the default scaling is not emitted' => ['cover=800x450,s:down', 'cover=800x450'];
        yield 'the default gravity is not emitted' => ['cover=800x450,g:center', 'cover=800x450'];
        yield 'a resolved ratio drops out' => ['cover=800x450,r:1.7777777777777777', 'cover=800x450'];
        yield 'empty steps are dropped' => ['cover=800x450||grayscale', 'cover=800x450|grayscale'];
        yield 'whitespace around a step is trimmed' => [' cover=800x450 | grayscale ', 'cover=800x450|grayscale'];
    }

    /**
     * The canonical form is what a cache key is built from, so normalisation has
     * to be idempotent: parsing the output again must not move it further.
     */
    #[DataProvider('normalised')]
    public function testNormalisationIsIdempotent(string $input, string $canonical): void
    {
        $once = (string) Transform::parse(rawurldecode($input));

        self::assertSame($canonical, $once);
        self::assertSame($canonical, (string) Transform::parse($once));
    }

    /**
     * A comma separates arguments, so a colour written as a function cannot
     * appear in a transform string at all.
     *
     * The PHP API takes `rgb(230, 57, 70)` and every other form Colour reads;
     * the string form takes hex and the sixteen names, and normalises whatever
     * the API was given down to hex. A grammar that tried to accept both would
     * need quoting, and a transform that needs quoting is not a URL segment.
     */
    public function testAColourFunctionIsNotPartOfTheStringGrammar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Transform::parse('tint=rgb(230,57,70)');
    }

    public function testAnUnknownOperationNamesTheOnesItKnows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown operation "duotone"');

        Transform::parse('cover=800x450|duotone=e63946');
    }

    public function testParsingCanBeLimitedToExplicitOperationNames(): void
    {
        self::assertSame(
            'cover=100x100',
            (string) Transform::parse('cover=100x100', only: ['cover']),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown operation "overlay"');

        Transform::parse(
            'cover=100x100|overlay=secret.png',
            only: ['cover'],
        );
    }

    public function testAnUnknownAllowedOperationIsRejectedAsConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot allow unknown operation "thumbnail"');

        Transform::parse('', only: ['thumbnail']);
    }

    public function testInvalidOperationArgumentsAreRejectedAtConstruction(): void
    {
        $invalid = [
            static fn(): Resize => new Resize(0),
            static fn(): Resize => new Resize(height: 0),
            static fn(): Resize => new Resize(ratio: 0),
            static fn(): Crop => new Crop(0, 10),
            static fn(): Crop => new Crop(10, 10, x: 1),
            static fn(): Crop => new Crop(10, 10, Anchor::TopLeft, 1, 1),
            static fn(): Extend => new Extend(top: -1),
            static fn(): Overlay => new Overlay(''),
            static fn(): Overlay => new Overlay('mark.png', opacity: -0.1),
            static fn(): Overlay => new Overlay('mark.png', margin: -1),
            static fn(): Adjust => new Adjust(brightness: 101),
            static fn(): Adjust => new Adjust(gamma: 0.01),
            static fn(): Blur => new Blur(0),
            static fn(): Pixelate => new Pixelate(0),
            static fn(): Rotate => new Rotate(\INF),
            static fn(): Sharpen => new Sharpen(0),
            static fn(): Sharpen => new Sharpen(amount: 6),
            static fn(): Tint => new Tint(0xFF000000, 1.1),
            static fn(): Trim => new Trim(256),
            static fn(): IccConvert => new IccConvert(' '),
            static fn(): Flip => Flip::parse(['0' => 'diagonal']),
        ];

        foreach ($invalid as $construct) {
            try {
                $construct();
                self::fail('An invalid operation argument was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $metadata = new Metadata(new Size(10, 10), Format::Png);
        self::assertSame($metadata, (new Overlay('mark.png'))->project($metadata));
        self::assertSame('flip=h', (string) Flip::horizontal());
        self::assertSame('flip=v', (string) Flip::vertical());
        self::assertSame(Metadata::GRAY, (new IccConvert('gray'))->project($metadata)->colourSpace);
        self::assertSame(Metadata::CMYK, (new IccConvert('cmyk'))->project($metadata)->colourSpace);
        self::assertSame('extend=2', (string) Extend::all(2));

        try {
            Crop::parse(['0' => '10']);
            self::fail('A crop without a height was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('<width>x<height>', $error->getMessage());
        }
    }

    /**
     * Extensibility seam one: a map, not a registry class.
     */
    public function testAThirdPartyOperationNeedsNothingButAMapEntry(): void
    {
        $transform = Transform::parse(
            'cover=200x200|double',
            extensions: ['double' => Doubling::class],
        );

        self::assertSame('cover=200x200|double', (string) $transform);
        self::assertSame(
            '400x400',
            (string) $transform->project(new Metadata(new Size(1000, 1000), Format::Png))->size,
        );
        self::assertSame(
            'double',
            (string) Transform::parse(
                'double',
                only: ['double'],
                extensions: ['double' => Doubling::class],
            ),
        );
    }

    public function testTheStepNameArrivesUnderTheReservedKey(): void
    {
        // Five names, one class, and the class has to learn which it was called by.
        foreach (['cover', 'contain', 'fill', 'inside', 'outside'] as $name) {
            $operation = Transform::parse($name . '=800x450')->operations[0];

            self::assertInstanceOf(Resize::class, $operation);
            self::assertSame($name, $operation->fit->value);
        }
    }

    public function testTheGrammarRefusesAnArgumentNameItReserves(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not lowercase alphanumeric');

        Transform::parse('cover=800x450,@:cover');
    }

    public function testAnEmptyTransformIsLegalAndSerialisesToNothing(): void
    {
        $transform = Transform::parse('');

        self::assertTrue($transform->isEmpty());
        self::assertSame('', (string) $transform);
        self::assertCount(0, $transform);
    }

    public function testResizeQueriesAndRewritesRespectOperationOrder(): void
    {
        $empty = Transform::new();
        self::assertNull($empty->resize());
        self::assertFalse($empty->contains(Resize::class));

        $appended = $empty->withResize(static fn(?Resize $resize): Resize => new Resize(320, 180));
        self::assertSame('cover=320x180', (string) $appended->reshape(
            static fn(?Resize $resize): Resize => ($resize ?? new Resize())->with(fit: \Alto\Image\Fit::Cover),
        ));

        $withTrailingOperation = Transform::parse('inside=640x480|blur=2');
        self::assertSame('inside=320x240|blur=2', (string) $withTrailingOperation->withResize(
            static fn(?Resize $resize): Resize => ($resize ?? new Resize())->with(width: 320),
        ));
        self::assertSame('inside=640x480|blur=2|cover=200x100', (string) $withTrailingOperation->reshape(
            static fn(?Resize $resize): Resize => new Resize(200, 100, \Alto\Image\Fit::Cover),
        ));
        self::assertSame(640, $withTrailingOperation->resize()?->width);
        self::assertTrue($withTrailingOperation->contains(Blur::class));
    }

    public function testProjectionRunsEveryStepInOrder(): void
    {
        $projected = Transform::parse('cover=800x600,s:both|rotate=90|extend=10')
            ->project(new Metadata(new Size(4000, 3000), Format::Jpeg));

        // 800x600, turned to 600x800, then ten pixels on every side.
        self::assertSame('620x820', (string) $projected->size);
    }

    public function testAnEscapeRefusesToSerialiseAndSaysWhy(): void
    {
        $transform = Transform::new()->with(new Escape(static fn(mixed $h): mixed => $h, 'duotone'));

        $this->expectExceptionMessage('Cannot serialise the "duotone" operation');

        (string) $transform;
    }

    public function testAnEscapeRefusesProjectionWithoutPretendingToBePortable(): void
    {
        $escape = new Escape(static fn(mixed $handle): mixed => $handle, 'duotone');

        $this->expectException(\Alto\Image\Exception\UnmeasurableException::class);
        $this->expectExceptionMessage('Cannot project through the "duotone" operation');
        Transform::new()->with($escape)->project(new Metadata(new Size(10, 10), Format::Png));
    }

    public function testExternalDependenciesParticipateInCacheIdentity(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'alto-overlay-');
        self::assertIsString($path);

        try {
            file_put_contents($path, 'first');
            $overlay = Transform::new()->with(new Overlay($path));
            $profile = Transform::new()->with(new IccConvert($path));
            $before = [$overlay->signature(), $profile->signature()];

            file_put_contents($path, 'second upload');

            self::assertNotSame($before[0], $overlay->signature());
            self::assertNotSame($before[1], $profile->signature());
        } finally {
            @unlink($path);
        }
    }

    public function testAnEscapeCanBeSignedOnlyWithAnExplicitIdentity(): void
    {
        $unsigned = Transform::new()->with(new Escape(static fn(mixed $handle): mixed => $handle));

        try {
            $unsigned->signature();
            self::fail('A closure invented its own stable cache identity.');
        } catch (\Alto\Image\Exception\UnmeasurableException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(
            'escape=duotone-v2',
            Transform::new()->with(new Escape(static fn(mixed $handle): mixed => $handle, identity: 'duotone-v2'))->signature(),
        );
    }

    public function testPortableOperationsUseTheirCanonicalFormAsCacheIdentity(): void
    {
        self::assertSame('grayscale', Transform::parse('grayscale')->signature());
    }

    public function testTheDefaultMapCoversEveryOperationTheImageApiCanBuild(): void
    {
        $names = array_keys(Transform::defaults());

        foreach (['cover', 'contain', 'fill', 'inside', 'outside', 'crop', 'extend', 'trim', 'rotate',
            'flip', 'orient', 'flatten', 'overlay', 'blur', 'sharpen', 'adjust', 'grayscale',
            'invert', 'pixelate', 'tint', 'icc'] as $name) {
            self::assertContains($name, $names, \sprintf('"%s" is not in the default map.', $name));
        }
    }
}
