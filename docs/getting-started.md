# Getting started

Create and inspect a first derivative without configuring a driver or service.

## Create one image

Open a source, describe the output, then save it:

```php
use Alto\Image\Image;

$result = Image::open('photo.jpg')
    ->cover(800, 450)
    ->webp(80)
    ->save('public/hero.webp');
```

The request is immutable and lazy. It does not decode the source until
`save()`, `store()`, `render()`, `bytes()`, `dataUri()`, or `analyze()` is
called.

Source dimensions use the EXIF display orientation. Rendering applies that
orientation automatically.

The output path does not select the format. Call `webp()`, `jpeg()`, `png()`,
`avif()`, or `encode()` explicitly before saving to a differently named format.

## Inspect a request

Inspection reads the source header and projects the requested result without
decoding pixels:

```php
$image = Image::open('photo.jpg')
    ->fit(1600, 1600)
    ->webp();

$sourceSize = $image->sourceSize();
$outputSize = $image->size();
$metadata = $image->metadata();
$cacheKey = $image->signature();
```

An operation that cannot project its output throws `UnmeasurableException` for
`size()` and `metadata()`. Rendering may still be possible.

## Read the result

Terminals return a `Result` with the encoded bytes and what the driver actually
produced:

```php
$result = $image->render();

$result->bytes;
$result->size();
$result->format();
$result->length();
$result->driver;
$result->duration;
$result->degradations;
$result->isExact();
```

`degradations` explains any behavior the selected driver could only approximate.
See [Driver selection and features](drivers/index.md) for capability details.

## Next steps

- [Transform an image](transformations.md)
- [Configure encoding](encoding.md)
- [Create several outputs](image-sets.md)
- [Cache derivatives in a store](storage.md)
- [Configure metadata and limits](metadata-and-safety.md)
