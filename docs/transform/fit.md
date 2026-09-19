# Fit

`fit()` scales an image inside a box without cropping or padding.

## Example

| Source: 768 x 432 | Result: 320 x 180 |
| --- | --- |
| ![Landscape source](../assets/examples/source-landscape.png) | ![Fitted image](../assets/examples/fit.png) |

```php
use Alto\Image\Image;

Image::open('source-landscape.png')
    ->fit(320, 240)
    ->save('fit.png');
```

## Options

| Option | Default | Use |
| --- | --- | --- |
| `width`, `height` | `null` | Maximum box |
| `scaling` | `Scaling::Down` | Allowed scale direction |

## Edge cases

- A different source ratio produces a smaller dimension on one axis.
- A smaller source is unchanged by default.
- One axis can be omitted.

## Driver support

GD and Imagick support `fit()` exactly.
