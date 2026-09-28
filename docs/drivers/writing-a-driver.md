# Writing a driver

A driver converts a negotiated `Plan` into an ordered list of encoded byte strings.
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
| `canEncode(Encoding $encoding, ?Metadata $source = null): Support` | Check an output request |
| `process(Plan $plan): array` | Return an ordered list of encoded byte strings |

`supports()`, `canDecode()`, and `canEncode()` are called during negotiation.
Return `Support::Approximate` when the driver can complete the work with losses.
Negotiation records these approximations in `Plan::$degradations`. The optional
source metadata allows a driver to reject requirements such as preserving an
embedded ICC profile.

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

## Test the driver

The package's conformance helpers live in `tests/Support` and are not part of the
distributed API. A driver package should own tests for its projected geometry,
format and metadata preservation, ordered batches, pass-through behavior,
source limits, malformed inputs, and encoding byte ceilings.

## Use the driver

Pass a driver to one request:

```php
use Acme\ImageVips\VipsDriver;
use Alto\Image\Image;

$bytes = Image::open('photo.jpg')
    ->using(new VipsDriver())
    ->cover(800, 450)
    ->webp()
    ->bytes();
```

Automatic detection includes only built-in drivers. Applications can select a
custom driver in their image service.
