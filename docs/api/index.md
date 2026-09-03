# Public API

The supported API is divided into core request types, extension contracts,
built-in integrations, a driver testing toolkit, and package exceptions. Types
marked `@internal` are implementation details and are not compatibility
promises.

## API areas

- [Core API](core.md): create, inspect, transform, encode, and execute requests.
- [Extension contracts](extension-contracts.md): drivers, operations, stores,
  analyzers, and driver testing tools.
- [Exceptions](exceptions.md): package-wide and specific failure handling.

## Execution model

`Image` represents one requested output. `ImageSet` represents several outputs
from the same source. Both are immutable and lazy.

| Capability | `Image` | `ImageSet` |
| --- | --- | --- |
| Transform and encode | one output | every output |
| Inspect size and metadata | directly | by iterating images |
| Save to a named path | yes | no |
| Render | one `Result` | list of `Result` |
| Store | one `Result` | list of `Result` |
| Count, iterate, select | no | yes |

The package follows semantic versioning for this surface. New enum cases,
formats, operations, capabilities, and optional integrations may be added in
minor releases where the contract permits extension.
