# Writing a driver

A driver converts a negotiated `Plan` into an ordered list of `Result` objects.
It reports capabilities before decoding and processes all requested outputs in
one batch.

## Implement the contract

`DriverInterface` defines six methods:

| Method | Responsibility |
| --- | --- |
| `name(): string` | Return the driver name |
| `capabilities(): Capabilities` | Describe the installed build |
| `supports(OperationInterface $operation): Support` | Check one operation |
| `canDecode(Format $format): Support` | Check an input format |
| `canEncode(Encoding $encoding): Support` | Check an output request |
| `process(Plan $plan): array` | Return an ordered list of `Result` objects |

`supports()`, `canDecode()`, and `canEncode()` are called during negotiation.
Return `Support::Approximate` only when `process()` can complete the work and
will record a specific explanation in `Result::$degradations`.

## Process a plan

Decode the source once and copy the decoded master for each output before
applying mutating operations. Return one result per requested output in the
plan's order.

ALTO Image handles these concerns before the driver runs:

- Source limits and basic input validation.
- Output projection and geometry solving.
- Driver negotiation.
- EXIF display-size projection.

The driver remains responsible for orienting decoded pixels consistently with
`$plan->source->metadata()->orientation`.

For a `Solvable` operation, call `solve()` with the raster size currently held
by the driver. The returned `Placement` defines scaling, cropping, and padding.
Solve after preceding operations, because rotation or trimming may change the
current dimensions.

Use `$plan->isPassThrough($index)` to identify an output that should reuse the
source bytes. Validate each encoded output against `$plan->output($index)` and
throw a `DriverException` if the result violates the projected contract.

## Run the conformance suite

```php
namespace Acme\ImageVips\Tests;

use Acme\ImageVips\VipsDriver;
use Alto\Image\Driver\DriverInterface;
use Alto\Image\Test\DriverTestCase;

final class VipsConformanceTest extends DriverTestCase
{
    protected function driver(): DriverInterface
    {
        return new VipsDriver();
    }
}
```

The suite verifies:

- Projected output metadata against encoded output metadata.
- Every declared readable and writable format.
- Per-instance support against the capability table.
- Degradation reporting for approximate work.
- Ordered batch output and pass-through behavior.
- Resize, crop, trim, extend, rotation, orientation, and alpha behavior.
- Resampling quality on checkerboards, hard edges, and flat colours.
- Source limits, malformed input, byte ceilings, and metadata stripping.
- Native-handle access through `escape()`.

## Use the driver

Pass a driver to one request:

```php
use Acme\ImageVips\VipsDriver;
use Alto\Image\Image;

$result = Image::open('photo.jpg')
    ->using(new VipsDriver())
    ->cover(800, 450)
    ->webp()
    ->render();
```

Automatic detection includes only built-in drivers. Applications can select a
custom driver in their image service.
