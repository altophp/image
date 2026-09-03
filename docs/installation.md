# Installation

Install ALTO Image and verify that the current PHP build can render images.

## Requirements

ALTO Image requires PHP 8.3 or later and Composer. Rendering requires one of
these extensions:

- GD
- Imagick

Header inspection, projected dimensions, signatures, and store paths do not
require either extension.

## Install the package

```bash
composer require alto/image
```

The package selects Imagick when available, then GD. Check the installed
drivers, formats, and extension configuration with:

```bash
vendor/bin/image doctor
```

Format support depends on how the PHP extension was compiled. The doctor output
is authoritative for the current machine. See [Drivers](drivers/index.md) for
the behavioral differences between GD and Imagick.

## Optional Flysystem support

Install Flysystem only when derivatives need to use a Flysystem adapter:

```bash
composer require league/flysystem
```

Local files use the built-in `LocalStore` and require no additional package.

Continue with [Getting started](getting-started.md).
