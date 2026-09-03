# GD

GD provides common raster formats through PHP's `gd` extension.

## Installation

Install or enable `ext-gd`, then inspect the compiled formats:

```console
$ php -m | grep '^gd$'
$ vendor/bin/image doctor
```

No additional PHP package is required.

## Capabilities

| Area | Support |
| --- | --- |
| Read | JPEG, PNG, GIF, WebP, AVIF, BMP when compiled in |
| Write | JPEG, PNG, GIF, WebP, AVIF, BMP when compiled in |
| Geometry and composition | Exact |
| Grayscale, invert, pixelate | Exact |
| Blur, sharpen, adjust, tint | Approximate |
| Metadata preservation | Not supported |
| ICC conversion | Not supported |
| Animated images | First frame |

Use `vendor/bin/image doctor` to inspect the current machine.

## Limits

- GD cannot read SVG, TIFF, or HEIC.
- WebP and GIF animation is reduced to the first frame.
- Some external libgd builds allocate pixel buffers outside `memory_limit`.
- GD decodes the full source before resizing.

Keep `Limits::maxPixels` enabled for untrusted images.

## Usage

ALTO Image selects GD automatically when Imagick is unavailable. Select it for
one request with `using()`:

```php
use Alto\Image\Driver\Gd\GdDriver;
use Alto\Image\Image;

$result = Image::open('photo.jpg')
    ->using(new GdDriver())
    ->fit(1200, 1200)
    ->webp()
    ->render();
```
