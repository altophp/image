# Core API

This page groups the stable public API by task. See the task guides for complete
examples.

## Requests

### `Image`

Create one lazy request with `Image::open(string|Source $source)`.

- Inspect: `source()`, `sourceSize()`, `sourceMetadata()`, `size()`,
  `metadata()`, `transform()`, `signature()`, `name()`.
- Execute: `save()`, `store()`, `render()`, `bytes()`, `dataUri()`, `analyze()`.
- Configure: all fluent transformation, encoding, metadata, limits, and driver
  methods listed below.

### `ImageSet`

Create a set with `widths()`, `heights()`, `formats()`, `and()`, or
`ImageSet::of()`. It supports the same fluent configuration as `Image`, plus
`count()`, `images()`, iteration, `select()`, `render()`, and `store()`.

## Sources and results

### `Source`

Create sources with `Source::file()`, `Source::bytes()`, or `Source::stream()`.
`Source::of()` accepts an existing source or a file path. Use `identifiedBy()`
to replace the default file fingerprint when application storage already
provides a stable version identifier.

Source header methods include `head()`, `tail()`, `length()`, `metadata()`,
`signature()`, and `origin()`. `contents()` reads the complete source.

### `Result`

A `Result` exposes `metadata`, `bytes`, `driver`, `degradations`, `duration`,
`path`, and `copied`. Convenience methods provide `size()`, `format()`,
`length()`, `isExact()`, and `dataUri()`.

## Image operations

The fluent request surface includes:

- Geometry: `cover()`, `contain()`, `fit()`, `scale()`, `stretch()`, `resize()`,
  `crop()`, `extend()`, `trim()`, `rotate()`.
- Orientation and composition: `flipHorizontal()`, `flipVertical()`, `orient()`,
  `flatten()`, `overlay()`.
- Effects: `blur()`, `sharpen()`, `adjust()`, `grayscale()`, `invert()`,
  `pixelate()`, `tint()`, `convertColourProfile()`.
- Extensibility: `apply()`, `transformedBy()`, `escape()`.

`Transform` creates, parses, projects, and serializes portable operation
sequences. Limit untrusted strings with `Transform::parse($value, only: [...])`.

See [Image operations](../transformations.md) for behavior and examples.

## Encoding

Use `jpeg()`, `png()`, `webp()`, or `avif()` for common output settings. Use
`encode()` for the complete configuration.

See [Encoding](../encoding.md).

## Metadata policy

Use `keepMetadata()`, `keepColourProfile()`, or `withMetadata()` to control
output metadata.

See [Metadata and safety](../metadata-and-safety.md).

## Request policy

Use `using()` to select a driver and `within()` to set resource limits.

## Image sets

Use `widths()`, `heights()`, `formats()`, and `and()` to create an `ImageSet`.

See [Image sets](../image-sets.md) for batch behavior and examples.

## Value objects and enums

| Type | Purpose |
| --- | --- |
| `Metadata` | Source or projected format, dimensions, orientation, frames, profile, and byte facts |
| `Size` | Width and height calculations |
| `Limits` | Source and projected output safety policy |
| `Colour` | Parse and format packed RGBA colours |
| `Format` | Supported image formats and format properties |
| `Fit` | Resize fit behavior |
| `Scaling` | Upscaling and downscaling policy |
| `Anchor`, `Focus`, `FocalPoint` | Crop and placement gravity |
| `Effort` | Encoding speed and compression effort |
| `MetadataPolicy` | Output metadata retention |
| `FailOn` | Input completeness policy |
