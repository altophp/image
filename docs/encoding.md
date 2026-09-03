# Encoding

Encoding selects the output format and its compression settings.

## Named formats

Use a named method for common settings:

```php
use Alto\Image\Effort;
use Alto\Image\Image;

$image = Image::open('photo.png')
    ->fit(1600, 1600)
    ->webp(quality: 82, effort: Effort::Best);
```

Available methods are `jpeg()`, `png()`, `webp()`, and `avif()`.

## Full configuration

Use `encode()` for byte limits, progressive JPEG, or lossless output:

```php
use Alto\Image\Format;
use Alto\Image\Image;

$image = Image::open('photo.png')->encode(
    format: Format::Webp,
    quality: 82,
    maxBytes: 200_000,
);
```

A byte limit can require several encoding passes. Format support depends on the
selected [driver](drivers/index.md). Use `encode()` with another `Format` case
when the driver reports it as writable through `vendor/bin/image doctor`.
