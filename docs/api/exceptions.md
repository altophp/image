# Exceptions

Every package exception implements `ImageExceptionInterface`. Catch the marker
interface at an application boundary, or a concrete exception when recovery
differs by failure.

```php
use Alto\Image\Exception\ImageExceptionInterface;
use Alto\Image\Image;

$image = Image::open($path)->fit(1600, 1600)->webp();

try {
    $result = $image->render();
} catch (ImageExceptionInterface $error) {
    // Report an image-processing failure to the application boundary.
}
```

| Exception | Meaning |
| --- | --- |
| `InvalidArgumentException` | Invalid method argument or incompatible request |
| `SourceNotFoundException` | Source path or stream cannot be read |
| `CorruptImageException` | Invalid, malformed, or truncated image data |
| `LimitExceededException` | Source or projected output exceeds `Limits` |
| `UnsupportedOperationException` | No selected driver can perform the requested work |
| `DriverException` | Decoder, operation, or encoder failure |
| `StoreException` | Derivative lookup, write, lock, or pruning failure |
| `UnmeasurableException` | Output metadata cannot be projected without rendering |

Errors from application callbacks propagate unchanged.
