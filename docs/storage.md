# Storage

Use `save()` for one caller-selected path. Use a store for deterministic,
signature-keyed derivative paths and cache reuse.

## Save one image

```php
use Alto\Image\Image;

Image::open('photo.jpg')
    ->cover(800, 450)
    ->webp(80)
    ->save('public/hero.webp');
```

The write is atomic on the local filesystem. `save()` returns `void`. When no output format was selected, a recognised file extension selects
it. An explicitly configured format must match the extension; unknown
extensions leave the configured or source format unchanged.

## Use the local store

Passing a directory is the shortest form:

```php
$path = Image::open('photo.jpg')
    ->cover(800, 450)
    ->webp(80)
    ->store('public/media');
```

Create a `LocalStore` when the application also needs the path, cache status,
locking, or pruning:

```php
use Alto\Image\Image;
use Alto\Image\Store\LocalStore;

$store = new LocalStore('public/media');
$image = Image::open('photo.jpg')->cover(800, 450)->webp(80);

$path = $store->path($image);
$exists = $store->has($image);
$path = $store->ensureOne($image);
$removed = $store->prune(new DateTimeImmutable('-30 days'));
```

Path calculation uses source and request signatures. It does not decode pixels.
Local writes use a temporary file and atomic rename.

## Prevent duplicate work

Pass a critical-section closure when several workers may generate the same
derivative. It receives a source-specific key and a closure covering the cache
recheck, rendering, and publication:

```php
$store = new LocalStore(
    'public/media',
    criticalSection: fn (string $key, Closure $work) => $lock->run($key, $work),
);
```

The lock implementation belongs to the application so it can use the existing
process, cache, or distributed-lock infrastructure. Overlapping output sets for
the same source share a key. Return the paths produced by the closure.

## Use Flysystem

```php
use Alto\Image\Store\FlysystemStore;

$store = new FlysystemStore($filesystem, prefix: 'media');
$paths = $set->store($store);
```

`$filesystem` must implement `League\Flysystem\FilesystemOperator`. Flysystem
stores use adapter writes rather than local atomic rename. Files are written
with public visibility and the encoded MIME type.

Custom backends implement `StoreInterface`. The contract covers deterministic
path lookup, existence checks, writing one or several outputs, and pruning.
The built-in implementations are `LocalStore` and `FlysystemStore`.

## When saving fails

Check that the destination's parent can be created and written by the PHP
process. Local writes need space for a temporary file and an atomic rename;
a read-only directory or an existing directory at the file path cannot be a
valid destination. Check storage permissions and free space before retrying.
For remote stores, check adapter credentials and write permissions separately
from image decoding. See [exception contracts](errors.md).

## Read encoded bytes

`Image::bytes()` returns the encoded string and `Image::dataUri()` returns a data
URI. `ImageSet::bytes()` returns an ordered list of encoded strings. Stores
return paths without reading cached derivatives back into memory.

Use `Image::metadata()` for the projected metadata. To inspect the actual encoded
file, use `Source::file($path)->metadata()` after saving it.
