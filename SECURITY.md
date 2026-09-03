# Security policy

## Reporting a vulnerability

Do not report suspected vulnerabilities through a public issue. Email
[security@altocoda.com](mailto:security@altocoda.com) with the affected version,
impact, reproduction and any known mitigation.

Reports are accepted in English or French and handled through coordinated
disclosure.

## Input limits

`Limits` checks dimensions, pixel count, frame count and encoded size before a
driver decodes the source. Lower the defaults when accepting untrusted uploads.

```php
use Alto\Image\Image;
use Alto\Image\Limits;

Image::open($upload)
    ->within(new Limits(maxPixels: 20_000_000))
    ->fit(1600, 1600)
    ->webp()
    ->save($destination);
```

## Metadata

`MetadataPolicy::Strip` is the default. It prevents EXIF, GPS and other source
metadata from being copied to derivatives. Metadata preservation must be
requested explicitly.

## ImageMagick

ImageMagick delegates and resource limits are deployment concerns. Disable
unneeded coders and protocols in `policy.xml`; `resources/imagemagick-policy.xml`
is a restrictive starting point.

## Paths

Source and destination paths are not access-control boundaries. Validate every
user-controlled path before passing it to this package.

## Transform strings

`Transform::parse()` accepts registered operations and validates their
arguments. For untrusted input, name only the operations the endpoint needs:

```php
use Alto\Image\Transform;

Transform::parse(
    $value,
    only: ['cover', 'crop', 'sharpen'],
);
```

Do not allow `overlay` unless its paths are independently constrained.

Use input limits, authentication and signed URLs for public transformation
endpoints.
