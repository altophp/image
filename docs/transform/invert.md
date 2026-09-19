# Invert

`invert()` reverses every colour channel and keeps alpha unchanged.

## Example

| Source | Inverted |
| --- | --- |
| ![Landscape source](../assets/examples/source-landscape.png) | ![Inverted image](../assets/examples/invert.png) |

```php
use Alto\Image\Image;

Image::open('source-landscape.png')
    ->invert()
    ->save('invert.png');
```

## Options

No options.

## Edge cases

- Dimensions and alpha do not change.

## Driver support

GD and Imagick support `invert()` exactly.
