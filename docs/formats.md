# Image formats

ALTO Image recognizes a stable set of image formats, but recognition does not
mean that the installed driver can decode or encode every one. Check the four
distinct capabilities before choosing a workflow:

- **Known** means `Format` represents the format and its common extensions and
  media type.
- **Detectable** means ALTO can identify and inspect a supported source header.
- **Readable** means the selected GD or Imagick build can decode its pixels.
- **Writable** means that build can encode a requested output.

Run the deployment's capability check for the authoritative read and write
lists:

```console
$ vendor/bin/image doctor
```

## Supported formats

| Format | Common input names | Alpha | Animation | Notes |
| --- | --- | --- | --- | --- |
| JPEG | `.jpg`, `.jpeg`, `.jpe`, `.jfif` | No | No | Widely readable and writable; quality is lossy. |
| PNG | `.png` | Yes | No | Lossless output; metadata support depends on the driver. |
| WebP | `.webp` | Yes | Yes | Build-dependent read and write support; only the first input frame is used. |
| AVIF | `.avif`, `.avifs` | Yes | Yes | Requires a matching GD build or ImageMagick delegate. |
| JPEG XL | `.jxl` | Yes | Yes | Known by ALTO; current driver support depends on installed delegates. |
| HEIC | `.heic`, `.heif`, `.hif` | Yes | Yes | Imagick only when its ImageMagick build has a HEIC delegate. |
| TIFF | `.tif`, `.tiff` | Yes | No | Imagick support depends on its ImageMagick delegates. |
| GIF | `.gif` | Yes | Yes | GD and Imagick use the first input frame. |
| BMP | `.bmp`, `.dib` | No | No | GD support depends on its build; Imagick depends on delegates. |
| SVG | `.svg`, `.svgz` | Yes | No | Vector source; Imagick rasterizes it at its declared size. GD refuses it. |

Animation in the table describes the file format, not multi-frame processing
by this package. ALTO Image currently renders the first frame and reports that
approximation. SVG can be detected and planned without implying that a raster
driver is available.

## Select a format

`Format::of()` accepts a format name, extension, or media type. It normalizes
aliases such as `jpg`, `.tiff`, and `image/svg+xml`:

```php
use Alto\Image\Format;

$format = Format::of('image/webp');

echo $format->extension(); // webp
echo $format->mime();      // image/webp
```

The enum also exposes `supportsAlpha()`, `supportsAnimation()`, `isVector()`,
`isLossy()`, and a format-specific `defaultQuality()`.

## Check the active driver

Driver capabilities are discovered at runtime. `Capabilities::$reads` and
`Capabilities::$writes` contain the exact `Format` cases available in that
installed build. A filename extension alone does not prove read or write
support.

Use [Drivers](drivers.md) to compare GD and Imagick, and [Encoding](encoding.md)
to configure output quality, effort, byte limits, progressive JPEG, or lossless
output.
