# InterventionImage

Responsive image variation, visual analysis, and perceptual fingerprinting engine for ProcessWire `Pageimage` assets powered by Intervention Image v4.4 (`Vips`, `Imagick`, `GD`).

## Value type

`InterventionImage` (`Module`, `ConfigurableModule`) autoloaded on frontend requests. Attaches variation, markup, color extraction, perceptual hash, and cleanup hooks to `Pageimage` and `Page`.

## Module methods

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var InterventionImage $imgEngine */
$imgEngine = $this->wire()->modules->get('InterventionImage');

// Retrieve the underlying Intervention\Image\ImageManager instance
$manager = $imgEngine->manager();

// Calculate responsive dimensions for a breakpoint and aspect ratio preset
// Returns array{width: int, height: int, maxWidth: int, ratio: float}
$dims = $imgEngine->calculate(1200, null, 'landscape', 'l');

// Inspect pending delayed (.queue) variations globally ($page = null) or for a Page
// Returns array{total: int, items: list<array{target: string, dimensions: string, source: string}>}
$inspection = $imgEngine->inspectQueue($page, 50);

// Process pending delayed (.queue) variations in batch
// Returns array{processed: int, failed: int, remaining: int, errors: list<string>}
$batchResult = $imgEngine->processQueue($page, 50);

// Process a single .queue sidecar file directly
$encoded = $imgEngine->processQueueFile('/path/to/image.800x600.webp.queue');

// Clear generated variations and sidecar caches (.lqip.webp, .colors.json, .hashes.json, .queue)
// Returns array{variations: int, lqip: int, colors: int, hashes: int, queue: int, total: int, bytes: int}
$stats = $imgEngine->clearVariations($page, [
	'variations' => true,
	'lqip' => true,
	'colors' => true,
	'hashes' => true,
	'queue' => true,
	'dryRun' => false,
]);
```

## Pageimage & Page hooks

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */

// 1. Variation generation (returns Pageimage)
$sized = $image->size(800, 600, ['format' => 'webp', 'quality' => 85, 'sharpening' => 'medium']);
$preset = $image->size('landscape-1-2', ['format' => 'avif']);
$cropped = $image->crop(100, 50, 600, 400, ['format' => 'webp']);
$contained = $image->contain(800, 600, 'ffffff');
$watermarked = $image->watermark($logoPageimage, 'bottom-right', 60, 20, 20, 1200, 800);

// 2. Responsive output & markup
echo $image->render('landscape', ['loading' => 'eager', 'isFirst' => true, 'class' => 'hero-img']);
$attrs = $image->attrs(800, 600, ['isFirst' => true]);
$srcset = $image->srcset(800, 600, ['format' => 'webp']);
$lqipUri = $image->lqip(); // data:image/webp;base64,...

// 3. Visual color analysis (cached in {basename}.colors.json)
$dominant = $image->dominantColor(true); // '#1e293b'
$palette = $image->palette(6, true);     // ['#1e293b', '#38bdf8', ...]
$theme = $image->colorTheme(true);       // ['vibrant' => '#...', 'muted' => '#...', 'darkVibrant' => '#...', 'darkMuted' => '#...', 'lightVibrant' => '#...', 'lightMuted' => '#...']

// 4. Perceptual hashing & visual similarity (cached in {basename}.hashes.json)
// Supported strategies: 'dhash' (default, 16 hex chars), 'phash' (16 hex chars), 'ahash' (16 hex chars), 'bhash' (64 hex chars)
$hash = $image->imageHash('dhash');
$distance = $image->hashDistance($otherImageOrHex, 'dhash');
$isSimilar = $image->isSimilar($otherImageOrHex, 5, 'dhash');

// 5. Per-page variation and sidecar cleanup
$cleared = $page->clearImageVariations(['dryRun' => false]);
```

## Notes

- **Driver priority**: When `driver` is set to `auto`, runtime health checks (`DriverInterface::checkHealth()`) probe drivers in `vips -> imagick -> gd` order.
- **Sidecar files**: Visual analysis and delayed rendering store companion files alongside the original `Pageimage` (`{basename}.lqip.webp`, `{basename}.colors.json`, `{basename}.hashes.json`, `{variation}.queue`). Deleting a `Pagefile` or calling `$image->removeVariations()` automatically removes its sidecar files.
