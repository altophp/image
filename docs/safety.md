# Image safety

ALTO Image applies source limits before decoding. It strips EXIF, IPTC and XMP
by default while keeping the ICC colour profile, so untrusted uploads do not
publish private metadata or lose their intended colours.

## Metadata policies

| Policy | ICC profile | EXIF, IPTC, and XMP |
| --- | --- | --- |
| `MetadataPolicy::Strip` | remove | remove |
| `MetadataPolicy::ColourProfile` (default) | keep | remove |
| `MetadataPolicy::Copyright` | remove | keep copyright and author fields when supported |
| `MetadataPolicy::Keep` | keep | keep what the driver supports |

The default removes EXIF and GPS data but retains the colour profile. Use
`Strip` when the profile must be removed too.

```php
use Alto\Image\Image;

$colourManaged = Image::open('upload.jpg')->webp();
$stripped = $colourManaged->withMetadata(\Alto\Image\MetadataPolicy::Strip);
$archival = $colourManaged->keepMetadata();
```

Use `withMetadata()` for an explicit policy. GD cannot preserve source metadata
or ICC profiles. Imagick support depends on its compiled delegates. The selected
driver reports any approximation in `Result::$degradations`.

`keepColourProfile()` preserves a profile. `convertColourProfile()` transforms
pixels to a named profile; it is a separate operation and requires LCMS support
in Imagick.

Metadata filtering always re-encodes, even when geometry is unchanged: a header
sample cannot prove that private tags are absent later in the file. Explicit
`keepMetadata()` still permits byte-for-byte copies when all other settings are
unchanged. Re-encoding may recompress lossy formats; GD cannot retain ICC profiles
or multiple animation frames. Use Imagick when those need to survive filtering.

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

Imagick checks the actual sequence length with a metadata probe before decoding
pixels, and again before coalescing frames. Where available, its native list-length
limit also bounds decoding and is restored after each request. Host decoder policies
still govern the cost of probing compressed formats and external delegates.

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

Strict output limits also apply before each built-in transform, using the current
raster dimensions. Imagick rotation includes a two-pixel per-axis allowance for
the temporary native canvas. A later crop cannot hide an oversized intermediate
image. Trusted `escape()` callbacks remain responsible for their own allocations.

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

Report suspected vulnerabilities through the
[organization security policy](https://github.com/altophp/.github/blob/main/.github/SECURITY.md).

## Sources

`Image::open()` accepts a path or `Source`. Create a source explicitly with
`Source::file()`, `Source::bytes()`, or `Source::stream()`. `Source::of()` accepts
an existing source or path. Use `identifiedBy()` only when application storage
already provides a stable version identifier for cache signatures.

Header reads such as `metadata()`, `head()`, `tail()`, and `length()` do not
request a full pixel decode. `contents()` reads the complete source. Resolve
paths and enforce finite `Limits` before allowing a terminal image operation.
