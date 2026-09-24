# Changelog

Notable user-visible changes are documented here from the first public release.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.8.0] - 2026-09-24

### Added

- Infer the output format from a recognised `save()` path extension when no
  format was selected explicitly.
- Expand the custom-driver conformance corpus with progressive, grayscale,
  CMYK, colour-profile, device-EXIF, interlaced and animated fixtures.

### Changed

- Preserve embedded ICC colour profiles by default while continuing to strip
  EXIF, IPTC and XMP metadata.
- Transform animated images frame by frame with Imagick when the output format
  supports animation.
- Move the `contain()` background argument after ratio, gravity and scaling.
  Calls that passed a background positionally must use the named `background`
  argument or the new sixth position.
- Reject recognised `save()` extensions that conflict with an explicitly
  selected output format.

## [0.7.0] - 2026-09-21

### Added

- Initial release.

[0.8.0]: https://github.com/altophp/image/releases/tag/v0.8.0
[0.7.0]: https://github.com/altophp/image/releases/tag/v0.7.0
