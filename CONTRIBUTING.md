# Contributing to ALTO Image

Contributions should preserve projected dimensions, safe defaults, honest
driver capabilities and the extension-free planning layer.

## Local setup

```bash
composer install
composer validate --strict
composer qa
```

`composer qa` runs PHP CS Fixer, PHPStan at level max and PHPUnit. All checks
must pass before opening a pull request.

## Changes

Add or update tests for observable behavior. Update `README.md`, `docs/` and
`CHANGELOG.md` when the public contract changes.

Keep changes inside an existing package boundary whenever possible:

| Change | Contract |
| --- | --- |
| Portable image operation | `PortableOperationInterface` |
| Image backend | `DriverInterface` and the [driver guide](docs/drivers/writing-a-driver.md) |
| Derivative destination | `StoreInterface` |
| Image analysis | `AnalyzerInterface` over `Raster` |

`tests/Unit` must run without GD or Imagick. Driver behavior belongs in
`tests/Driver`; malformed input belongs in `tests/Fuzz`.

## Style

The package uses PER-CS 2.0, strict types, ordered imports and the ALTO license
header. Run `composer cs:fix` to apply the coding standard.

Comments should explain constraints, portability requirements or non-obvious
behavior rather than restating the code.
