# Grayscale

`grayscale()` removes colour while preserving luminance.

## Example

| Source | Grayscale |
| --- | --- |
| ![Landscape source](../assets/examples/source-landscape.png) | ![Grayscale image](../assets/examples/grayscale.png) |

```php
use Alto\Image\Image;

Image::open('source-landscape.png')
    ->grayscale()
    ->save('grayscale.png');
```

## Options

No options.

## Edge cases

- Dimensions and alpha do not change.

## Driver support

GD and Imagick support `grayscale()` exactly.
