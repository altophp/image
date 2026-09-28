# Getting started

Create an 800 by 450 WebP cover from a supplied image. Follow
[Installation](installation.md) first and run `vendor/bin/image doctor` to check
that GD or Imagick can read PNG and write WebP.

Download the [example source](https://raw.githubusercontent.com/altophp/image/main/docs/assets/examples/first-source.png) into your project
as `source.png`. This original ALTO documentation illustration is 1200 by 800
pixels and is distributed under the repository's MIT license.

Save this as `thumbnail.php` beside `vendor/`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Image\Image;

$output = __DIR__.'/public';
if (!is_dir($output)) {
    mkdir($output, 0775, true);
}

Image::open(__DIR__.'/source.png')
    ->cover(800, 450)
    ->webp(80)
    ->save($output.'/hero.webp');

printf("%d x %d %s\n", $result->size()->width, $result->size()->height, $result->format()->value);
```

Run `php thumbnail.php`. It writes `public/hero.webp` and prints:

```text
800 x 450 webp
```

| Source: 1200 x 800 | Cover: 800 x 450 |
| --- | --- |
| ![Original landscape illustration with sky, hills, and a sun](assets/examples/first-source.png) | ![Actual WebP cover, cropped vertically to a 16:9 frame](assets/examples/first-cover.webp) |

`cover()` fills the requested box and crops the excess. The source remains
unchanged. The default avoids enlargement, so use a sufficiently large source
when reproducing these exact dimensions. See [Cover](transform/cover.md) for
scaling and crop placement.

## Understand the result

The request is immutable and lazy: pixel decoding happens at `save()` here.
The explicit `webp(80)` call selects the format and quality. When no format is
configured, `save()` infers it from a recognised filename extension. ALTO applies
the source's display orientation when rendering.

`save()` returns `void`; it writes the image or throws an exception. Use
`bytes()` for an encoded string and `dataUri()` for an inline data URI.

## Continue

- [Choose an operation](transform.md) for cropping, padding, or preserving the full image.
- [Inspect dimensions and metadata](safety.md) before rendering.
- [Configure encoding](formats.md#encode-output) for quality and format options.
- [Produce several outputs](image-sets.md) while sharing decoded pixels.
- [Save or cache derivatives](storage.md), including write-failure handling.
