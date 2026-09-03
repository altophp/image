# Blur

`blur()` applies a Gaussian blur.

## Example

| Source | Sigma 5 |
| --- | --- |
| ![Landscape source](../assets/examples/source-landscape.png) | ![Blurred image](../assets/examples/blur.png) |

```php
use Alto\Image\Image;

Image::open('source-landscape.png')
    ->blur(5)
    ->save('blur.png');
```

## Options

| Option | Default | Use |
| --- | --- | --- |
| `sigma` | `1.0` | Blur strength |

## Edge cases

- Sigma must be finite and greater than zero.
- Large values cost more and remove more detail.
- Dimensions and alpha do not change.

## Driver support

Imagick is exact. GD approximates sigma with repeated fixed-kernel passes.
