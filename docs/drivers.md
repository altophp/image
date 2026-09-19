# Drivers

ALTO Image selects Imagick when it is available, then GD. Override selection for
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

Run `vendor/bin/image doctor` for the formats and delegates available on the
current machine.

## Built-in drivers

- [GD](drivers/gd.md)
- [Imagick](drivers/imagick.md)

| Feature | GD | Imagick |
| --- | --- | --- |
| Raster input and output | Depends on compiled formats | Depends on compiled delegates |
| Animated input | First frame only | First frame only |
| Vector input | No | Rasterized at declared size |
| Metadata preservation | No | Supported where the delegate permits it |
| ICC profile conversion | No | Requires LCMS |
| Local operation behavior | Some effects are approximate | Arbitrary-angle rotation may be approximate |
| Batch execution | One decode per rendered source batch | One decode per rendered source batch |

First-frame reads and vector rasterization are reported as `Approximate`, not
silent exact support. Encoding effort and policy options can also vary by
format and driver.

## Capability levels

Drivers answer each concrete request with one of three levels:

- `Exact`: performed as specified.
- `Approximate`: performed with a documented degradation.
- `No`: refused during negotiation.

When work is approximate, `Result::$degradations` explains the difference.
Negotiation fails before decoding when no driver can perform the request.

## Third-party drivers

Implement `DriverInterface` and verify it with the public conformance toolkit.
See [Writing a driver](drivers/writing-a-driver.md).

The driver contract reports a name, build capabilities, support for each
operation, decode support for a source format, encode support for an output
request, and processes one negotiated `Plan`. `Capabilities` exposes the exact
read and write formats plus diagnostic notes for the installed build.

The supported testing namespace contains `ArrayDriver`, `Corpus`,
`DriverTestCase`, and `ImageAssertions`. Extend `DriverTestCase` to apply the
same behavioral contract used by the built-in drivers.
