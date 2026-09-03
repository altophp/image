# Command line

Composer installs `vendor/bin/image` with ALTO Image. Use it to inspect the
local image drivers, read image headers, or convert one image.

## Commands

| Command | Purpose |
| --- | --- |
| `doctor` | Inspect installed drivers and formats |
| `info <file>...` | Read image headers without decoding |
| `convert <source> <destination> [transform]` | Transform and write one image |

## `doctor`

Display the PHP version, memory limit, installed drivers, supported formats and
driver warnings:

```console
$ vendor/bin/image doctor
```

Format support depends on the installed GD and ImageMagick builds. See
[Drivers](drivers/index.md) for the selection rules and driver limits.

## `info`

Inspect one or more image files without decoding their pixels:

```console
$ vendor/bin/image info photo.jpg portrait.png
```

The command reports the format, stored dimensions, orientation, alpha channel,
frame count, colour space, metadata presence, byte size and source signature.
It reports whether metadata exists without printing its contents.

## `convert`

Convert an image using the destination extension as the output format:

```console
$ vendor/bin/image convert photo.jpg photo.webp
```

Pass a serialized transform as the third argument:

```console
$ vendor/bin/image convert photo.jpg hero.webp "cover=1280x720,g:attention|sharpen"
```

The command reports the source, projected dimensions, selected driver, written
file and any degradation. See [Operations](transformations.md) for transform
syntax and [Encoding](encoding.md) for output formats.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success |
| `1` | Image processing failed |
| `2` | Unknown command or missing required argument |
