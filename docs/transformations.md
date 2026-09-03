# Image operations

Image operations change pixels or geometry. Metadata policies are documented
separately under [Metadata and safety](metadata-and-safety.md).

## Resize

- [Cover](operations/cover.md)
- [Contain](operations/contain.md)
- [Fit](operations/fit.md)
- [Scale](operations/scale.md)
- [Stretch](operations/stretch.md)
- [Resize](operations/resize.md)

## Geometry

- [Crop](operations/crop.md)
- [Extend](operations/extend.md)
- [Trim](operations/trim.md)
- [Rotate](operations/rotate.md)
- [Flip](operations/flip.md)
- [Orient](operations/orient.md)

## Composition

- [Flatten](operations/flatten.md)
- [Overlay](operations/overlay.md)

## Effects

- [Blur](operations/blur.md)
- [Sharpen](operations/sharpen.md)
- [Adjust](operations/adjust.md)
- [Grayscale](operations/grayscale.md)
- [Invert](operations/invert.md)
- [Pixelate](operations/pixelate.md)
- [Tint](operations/tint.md)

## Colour

- [Convert a colour profile](operations/convert-colour-profile.md)

Every method returns a new `Image` or `ImageSet`. The source is unchanged.
Drivers can report an approximate result through `Result::$degradations`.
