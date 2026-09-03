# Imagick

Imagick exposes ImageMagick formats, delegates, and colour management to PHP.

## Installation

Install ImageMagick and `ext-imagick` 3.7 or later, then inspect the delegates:

```console
$ php -m | grep '^imagick$'
$ vendor/bin/image doctor
```

Format support belongs to the installed ImageMagick build. LCMS is required for
ICC conversion.

## Capabilities

| Area | Support |
| --- | --- |
| Read | JPEG, PNG, WebP, AVIF, HEIC, TIFF, GIF, BMP, SVG when delegates exist |
| Write | Raster formats provided by the build |
| Geometry and composition | Exact, except free-angle rotation |
| Blur, sharpen, adjust, tint | Exact |
| Metadata preservation | Supported when the delegate permits it |
| ICC conversion | Supported with LCMS |
| Animated images | First frame |

Use `vendor/bin/image doctor` to inspect the current machine.

## Limits

- SVG is rasterized at its declared size.
- Animated input is reduced to the first frame.
- Free-angle rotation can differ by one or two edge pixels.
- Available formats vary with ImageMagick delegates.

Imagick can shrink supported sources during decode to reduce memory use.

## Usage

ALTO Image selects Imagick first when it is available. Select it explicitly
with `using()`:

```php
use Alto\Image\Driver\Imagick\ImagickDriver;
use Alto\Image\Image;

$result = Image::open('photo.tiff')
    ->using(new ImagickDriver())
    ->fit(1200, 1200)
    ->webp()
    ->render();
```
