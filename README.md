# ALTO Image

Resize, crop and encode images from PHP with lazy execution, predictable output
geometry and one decode for multiple derivatives.

&nbsp; ![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-00B7FF?logoColor=00B7FF&labelColor=050608)
&nbsp; ![CI](https://img.shields.io/github/actions/workflow/status/altophp/image/CI.yml?branch=main&label=Tests&labelColor=050608&color=00B7FF)
&nbsp; [![Packagist](https://img.shields.io/packagist/v/alto/image?label=Packagist&labelColor=050608&color=00B7FF)](https://packagist.org/packages/alto/image)
&nbsp; ![License](https://img.shields.io/github/license/altophp/image?label=License&labelColor=050608&color=00B7FF)
&nbsp; [![GitHub Sponsors](https://img.shields.io/github/sponsors/smnandre?logo=githubsponsors&logoColor=00B7FF&label=%20Sponsor&labelColor=050608&color=00B7FF)](https://github.com/sponsors/smnandre)

`Image` represents one source and one requested output. `ImageSet` represents
several outputs from that source and renders them together. Both are immutable
and hold no decoded pixels.

Header inspection, projected dimensions, signatures and derivative paths need
no image extension. Rendering uses GD or Imagick.

## Features

- Crop by coordinates, anchor, focal point, attention or entropy.
- Preserve ICC profiles or convert pixels to another colour space with Imagick.
- Produce several sizes and formats from one source decode.
- Read files, encoded bytes and streams. Write files or storage backends.
- Encode common raster formats supported by the selected driver. Controls
  include quality, effort, byte limits, progressive output and lossless output.
- Extract dominant colours and compare images with perceptual hashes.

See [crop](docs/operations/crop.md),
[colour profile conversion](docs/operations/convert-colour-profile.md),
[encoding](docs/encoding.md) and [analysis](docs/analysis.md).

## Installation

Install ALTO Image with Composer:

```bash
composer require alto/image
```

ALTO Image requires PHP 8.3 or later. Rendering requires ext-gd or
ext-imagick.

Inspect the available formats and local extension configuration with:

```bash
vendor/bin/image doctor
```

## Quick start

```php
use Alto\Image\Image;

Image::open('photo.jpg')
    ->cover(800, 450)
    ->webp(80)
    ->save('hero.webp');
```

Drivers are detected automatically and safe input limits are applied before
decoding.

## Command line

Composer installs `vendor/bin/image` with three subcommands:

| Command | Purpose |
| --- | --- |
| [`image doctor`](docs/command-line.md#doctor) | Inspect installed drivers and formats |
| [`image info`](docs/command-line.md#info) | Read image headers without decoding |
| [`image convert`](docs/command-line.md#convert) | Transform and write one image |

Run them through `vendor/bin/image`. See the
[command-line reference](docs/command-line.md) for arguments, examples and exit
codes.

## Multiple outputs

Create a responsive image set and write it to a local derivative store:

```php
use Alto\Image\Format;
use Alto\Image\Image;

$results = Image::open('photo.jpg')
    ->cover(ratio: 16 / 9)
    ->widths(640, 960, 1280)
    ->formats(Format::Webp, Format::Avif)
    ->store('public/media');
```

The resulting `ImageSet` contains six images in the requested order. Missing
outputs are rendered together with one source decode.

Combine outputs with different shapes or qualities using `and()`:

```php
use Alto\Image\Image;

$source = Image::open('upload.jpg');

$results = $source->cover(1600, 900)->webp(82)
    ->and($source->cover(600, 400)->webp(80))
    ->and($source->cover(160, 160)->webp(75))
    ->store('public/media');
```

## Transformations

Named methods cover common geometry and encoding operations:

```php
use Alto\Image\Image;

$image = Image::open('photo.jpg')
    ->fit(1600, 1600)
    ->sharpen()
    ->webp(80);

$size = $image->size();
$key = $image->signature();
$result = $image->render();
```

Transforms can also be parsed from their stable string representation:

```php
use Alto\Image\Image;
use Alto\Image\Transform;

$transform = Transform::parse('cover=1280x720,g:top-right|sharpen');
$result = Image::open('photo.jpg')
    ->transformedBy($transform)
    ->webp()
    ->render();
```

## Storage

Pass a directory directly for local storage, or use a store object when the
application needs paths, pruning or another backend:

```php
use Alto\Image\Image;
use Alto\Image\Store\LocalStore;

$store = new LocalStore('public/media');
$image = Image::open('photo.jpg')->cover(800, 450)->webp();

$path = $store->path($image);
$result = $image->store($store);
$removed = $store->prune(new DateTimeImmutable('-30 days'));
```

`FlysystemStore` supports Flysystem adapters. Custom stores implement
`Store\StoreInterface`.

## Metadata and safety

Metadata is stripped by default, including EXIF and GPS data. Request only the
ICC profile with `keepColourProfile()`, or all supported metadata with
`keepMetadata()`.

```php
use Alto\Image\Image;
use Alto\Image\Limits;

Image::open($upload)
    ->within(new Limits(maxPixels: 20_000_000))
    ->fit(1600, 1600)
    ->webp()
    ->save($destination);
```

Read [SECURITY.md](SECURITY.md) before processing untrusted paths or transform
strings. Limit user-supplied transformations to the operations the endpoint
needs:

```php
use Alto\Image\Transform;

$transform = Transform::parse(
    $value,
    only: ['cover', 'crop', 'sharpen'],
);
```

## Documentation

- [Documentation index](docs/index.md)
- [Getting started](docs/getting-started.md)
- [Transformations](docs/transformations.md)
- [Image sets](docs/image-sets.md)
- [Encoding](docs/encoding.md)
- [Analysis](docs/analysis.md)
- [Storage](docs/storage.md)
- [Metadata and safety](docs/metadata-and-safety.md)
- [Command line](docs/command-line.md)
- [Public API](docs/api/index.md)
- [Drivers](docs/drivers/index.md)

## Contributing

Contributions of all kinds are welcome. Visit the
[project on GitHub](https://github.com/altophp/image) to
[report a bug](https://github.com/altophp/image/issues/new),
[suggest a feature](https://github.com/altophp/image/issues/new), or
[open a pull request](https://github.com/altophp/image/pulls).

Before submitting code, run:

```bash
# Runs PHP CS Fixer, PHPStan, and PHPUnit
composer qa

# Runs PHPUnit and enforces 100% line coverage
composer coverage
```

Changes to public behavior should include tests and documentation. See
[CONTRIBUTING.md](CONTRIBUTING.md) for package-specific guidance.

## Support

ALTO Image is open source. You can support its continued development through
[GitHub Sponsors](https://github.com/sponsors/smnandre).

Sharing this package with others or
[starring it on GitHub](https://github.com/altophp/image) is also much
appreciated.

## License

ALTO Image is released by [ALTO PHP](https://altophp.com) under the
[MIT License](LICENSE).
