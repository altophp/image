# Metadata and safety

ALTO Image applies source limits before decoding and strips metadata by default.
Keep the defaults for untrusted uploads unless the application has a specific
reason to change them.

## Metadata policies

| Policy | ICC profile | EXIF, IPTC, and XMP |
| --- | --- | --- |
| `MetadataPolicy::Strip` | remove | remove |
| `MetadataPolicy::ColourProfile` | keep | remove |
| `MetadataPolicy::Copyright` | remove | keep copyright and author fields when supported |
| `MetadataPolicy::Keep` | keep | keep what the driver supports |

`Strip` is the default and removes EXIF and GPS data.

```php
use Alto\Image\Image;

$private = Image::open('upload.jpg')->webp();
$colourManaged = $private->keepColourProfile();
$archival = $private->keepMetadata();
```

Use `withMetadata()` for an explicit policy. GD cannot preserve source metadata
or ICC profiles. Imagick support depends on its compiled delegates. The selected
driver reports any approximation in `Result::$degradations`.

`keepColourProfile()` preserves a profile. `convertColourProfile()` transforms
pixels to a named profile; it is a separate operation and requires LCMS support
in Imagick.

## Input and output limits

The default `Limits` policy is:

| Limit | Default |
| --- | ---: |
| Source pixels | 50,000,000 |
| Width or height | 32,768 |
| Animation frames | 512 |
| Encoded source bytes | 256 MiB |
| Truncated input | reject |
| Projected output checks | enabled |

Apply stricter limits to a request when needed:

```php
use Alto\Image\Image;
use Alto\Image\Limits;

$image = Image::open($upload)
    ->within(new Limits(
        maxPixels: 20_000_000,
        maxDimension: 8_192,
        maxBytes: 32 * 1024 * 1024,
    ));
```

Use `Limits::none()` only for sources produced and trusted by the application.

## Untrusted input

- Resolve user-controlled source paths against an allowed directory.
- Parse user-controlled transform strings with an explicit operation list:

```php
use Alto\Image\Transform;

$transform = Transform::parse(
    $value,
    only: ['cover', 'crop', 'sharpen'],
);
```

- Exclude `overlay` unless referenced files are independently constrained.
- Keep finite limits in place before any terminal operation.
- Treat `escape()` closures as trusted application code.

See the [security policy](../SECURITY.md) for supported versions, reporting, and the
full deployment boundary.
