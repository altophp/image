# Image sets

An `ImageSet` represents several outputs derived from one source. Missing
outputs are rendered together so the selected driver can decode the source once.

## Multiply widths and formats

```php
use Alto\Image\Format;
use Alto\Image\Image;

$set = Image::open('photo.jpg')
    ->cover(ratio: 16 / 9)
    ->widths(640, 960, 1280)
    ->formats(Format::Webp, Format::Avif);

$results = $set->store('public/media');
```

This request contains six outputs. `widths()`, `heights()`, and `formats()`
multiply the current outputs, preserve order, and do not remove duplicates.

If a resize already defines a box ratio, changing one axis preserves that
ratio. For example, `cover(1600, 900)->widths(640, 1280)` produces 640x360 and
1280x720 outputs.

## Combine different outputs

Use `and()` when outputs differ by more than one dimension or format:

```php
use Alto\Image\Image;

$source = Image::open('photo.jpg');

$set = $source->cover(1600, 900)->webp(82)
    ->and($source->cover(600, 400)->webp(80))
    ->and($source->cover(160, 160)->webp(75));

$results = $set->store('public/media');
```

All members must share the same source, limits, and driver. Configure `using()`
and `within()` before deriving the individual outputs.

## Inspect and select outputs

`ImageSet` implements `Countable` and `IteratorAggregate`:

```php
foreach ($set as $index => $image) {
    echo $index . ': ' . $image->size() . PHP_EOL;
}

$subset = $set->select(0, 2);
```

Indexes are zero-based. `select()` preserves the requested order.

## Render or store

`render()` returns one `Result` per output in memory. `store()` returns the same
shape and writes each derivative to a signature-keyed store. `ImageSet` has no
`save()` method because one path cannot name several outputs.

The one-decode guarantee applies to outputs rendered in the same batch. A store
renders only its missing outputs together; existing derivatives are reused.
