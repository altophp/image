# Image analysis

Analyzers receive a bounded raster from an `Image`. Calling `analyze()` may
decode the source.

## Extract dominant colours

```php
use Alto\Image\Analyzer\DominantColors;
use Alto\Image\Image;

$colours = Image::open('photo.jpg')->analyze(
    new DominantColors(count: 5, levels: 4),
);

foreach ($colours as $colour) {
    echo $colour['colour'] . ' ' . $colour['share'] . PHP_EOL;
}
```

Each item contains a CSS hexadecimal `colour`, its packed integer value, and its
`share` of visible sampled pixels. Fully transparent pixels are ignored.

## Compare images perceptually

```php
use Alto\Image\Analyzer\PerceptualHash;
use Alto\Image\Image;

$analyzer = new PerceptualHash();
$left = Image::open('original.jpg')->analyze($analyzer);
$right = Image::open('candidate.jpg')->analyze($analyzer);

$distance = PerceptualHash::distance($left, $right);
```

The hash is 16 hexadecimal digits. A distance of zero means the hashes are
identical; larger Hamming distances indicate greater visual difference.

## Write an analyzer

Implement `AnalyzerInterface<TResult>` and accept a `Raster` in `analyze()`.
The raster is bounded to 64 pixels on its longest side by default. It provides
packed pixels, RGBA channels, luma values, and bounded resampling.

Analyzers are suited to compact visual features. Use a dedicated processing
pipeline when an algorithm requires full-resolution pixels.
