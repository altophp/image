# Extension contracts

These contracts are public for integrations. Application code normally starts
with `Image` and uses the built-in implementations.

## Drivers

`DriverInterface` reports its name, capabilities, support for a concrete
operation, decode support for a format, encode support for an `Encoding`, and
processes a negotiated `Plan`.

| Type | Role |
| --- | --- |
| `DriverInterface` | Driver boundary |
| `Capabilities` | Build-level formats, operations, version, and notes |
| `Support` | `Exact`, `Approximate`, or `No` |
| `Encoding` | Output format, quality, effort, metadata, and encoder options |
| `Output` | One transform and encoding request |
| `Plan` | Negotiated source, driver, limits, and ordered outputs |

Built-in drivers are `GdDriver` and `ImagickDriver`. See
[Writing a driver](../drivers/writing-a-driver.md) for the implementation
contract and conformance suite.

## Operations

An executable operation implements `OperationInterface`. A portable operation
also implements `PortableOperationInterface`, which provides projection,
parsing, and stable serialization. Geometry operations may implement `Solvable`
and return a resolved `Placement`.

The built-in operation classes are `Adjust`, `Blur`, `Crop`, `Escape`, `Extend`,
`Flatten`, `Flip`, `Grayscale`, `IccConvert`, `Invert`, `Orient`, `Overlay`,
`Pixelate`, `Resize`, `Rotate`, `Sharpen`, `Tint`, and `Trim`.

Apply custom operations with `apply()`. Use `escape()` only for trusted,
driver-specific code. Supply a stable identity when its output affects cache
keys.

Portable operations can be serialized for URLs or stored configuration:

```php
use Alto\Image\Image;
use Alto\Image\Transform;

$transform = Transform::parse('cover=1280x720,g:top-right|sharpen');
$image = Image::open('photo.jpg')->transformedBy($transform);
```

Parse untrusted strings with `Transform::parse($value, only: [...])`. Exclude
`overlay` unless its paths are also constrained.

## Stores

`StoreInterface` defines deterministic path lookup, existence checks, one and
many output writes, and pruning. The built-in implementations are `LocalStore`
and `FlysystemStore`.

See [Storage](../storage.md) for usage and backend behavior.

## Analyzers

`AnalyzerInterface<TResult>` receives a bounded `Raster` and returns an
application-defined result. Built-in analyzers are `DominantColors` and
`PerceptualHash`.

See [Image analysis](../analysis.md).

## Driver testing toolkit

The `Alto\Image\Test` namespace is supported public API for third-party drivers:

- `ArrayDriver`
- `Corpus`
- `DriverTestCase`
- `ImageAssertions`

Extend `DriverTestCase` to apply the same behavioral contract used by the
built-in drivers.
