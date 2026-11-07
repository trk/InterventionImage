---
description: "Use when resizing, cropping, containing, watermarking, extracting dominant colors/palettes/Vibrant-Muted themes/LQIP, computing perceptual image hashes (dhash, phash, ahash, bhash) or similarity distance, or rendering responsive HTML/srcset for ProcessWire Pageimage objects via InterventionImage (Intervention Image v4.4 with Vips/Imagick/GD), or when running CLI queue and variation cleanup tasks."
---

# ProcessWire InterventionImage Module Guidelines

`InterventionImage` replaces ProcessWire's default frontend sizing engine with **Intervention Image v4.4** (`intervention/image: ^4.4`, `intervention/image-driver-vips: ^4.2`, `intervention/imagehash: ^1.0`, `intervention/gif: ^5.0`), providing `Vips` (`libvips`) / `Imagick` / `GD` driver auto-detection (`checkHealth()`), delayed `.queue` rendering, pixel-accurate focal-point + zoom cropping, responsive `<picture>` / `srcset` markup, Base64 LQIP placeholders, `ColorExtractor` palettes, and perceptual hashing (`imageHash`, `hashDistance`, `isSimilar`).

## Context Pointers

- **API Reference (`site/modules/InterventionImage/API.md`)**: Read when calling `InterventionImage` module methods (`manager()`, `calculate()`, `inspectQueue()`, `processQueue()`, `clearVariations()`) or checking exact `Pageimage` hook return types.
- **Domain Glossary (`site/modules/InterventionImage/GLOSSARY.md`)**: Read when naming variables, array keys, or docblocks involving `Variation`, `Delayed Queue`, `LQIP`, `Color Metadata`, `Perceptual Hash`, `Hamming Distance`, or `Sidecar Cache`.
- **ADR-0001 Driver Fallback (`site/modules/InterventionImage/docs/adr/0001-vips-driver-health-fallback.md`)**: Read when modifying driver selection, `isDriverHealthy()`, or `ffi.enable=preload` SAPI diagnostics.
- **ADR-0002 Hash Sidecar (`site/modules/InterventionImage/docs/adr/0002-perceptual-hash-sidecar-cache.md`)**: Read when modifying `.hashes.json` persistence, hash strategies (`dhash`, `phash`, `ahash`, `bhash`), or cache invalidation.

## 1. Target Conventions & Guardrails

- **Operate through `Pageimage` hooks**: Call `$image->size()`, `$image->crop()`, `$image->contain()`, `$image->watermark()`, `$image->render()`, `$image->srcset()`, `$image->attrs()`, `$image->lqip()`, `$image->dominantColor()`, `$image->palette()`, `$image->colorTheme()`, `$image->imageHash()`, `$image->hashDistance()`, and `$image->isSimilar()` so variations use deterministic ProcessWire suffixes (`createSuffix()`) and sidecar caches (`*.queue`, `*.lqip.webp`, `*.colors.json`, `*.hashes.json`).
- **Use Intervention Image v4.4 APIs**: When interacting with `$module->manager()`, call `decodePath()` / `decode()`, `grayscale()`, `flip()`, `cover()` / `coverDown()`, `contain()` / `containDown()`, `colorAt()`, and `encodeUsingFormat(Format::WEBP, quality: 85)`.
- **Pass integer dimensions or configured preset keys to `size()`**: Call `$image->size(800, 600, $options)` or `$image->size('landscape', $options)` (ProcessWire's upstream `Pageimage::size()` wrapper treats any string first argument as a `$config->imageSizes` preset key).
- **Pass pixel coordinates first to `crop()`**: Call `$image->crop($x, $y, $width, $height, $options)` for coordinate cutouts; for compass-aligned dimension crops, call `$image->size($width, $height, ['cropping' => 'north'])`.

## 2. Pageimage Hook Signatures & Options

### Variation Hooks (`Pageimage` return)

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */

// 1. Standard or preset-based resize/cover (auto-applies ProcessWire focal point & zoom on center crops)
$thumb = $image->size(600, 400, ['quality' => 85, 'format' => 'webp']);
$preset = $image->size('landscape-1-2', ['sharpening' => 'medium']);

// 2. Proportional scale without cropping
$scaled = $image->size(1200, 0, ['cropping' => false, 'upscaling' => false]);

// 3. Explicit pixel-coordinate crop: crop($x, $y, $width, $height = 0, $options = [])
$cutout = $image->crop(100, 50, 600, 400, ['format' => 'webp']);

// 4. Fit & pad inside exact box without cropping: contain($width, $height = null, $options = [])
$padded = $image->contain(800, 600, 'ffffff');

// 5. Watermark overlay: watermark($watermark, $position = Alignment::BOTTOM_RIGHT, $opacity = 50, $offsetX = 15, $offsetY = 15, $width = null, $height = null, $options = [])
$branded = $image->watermark($logoPageimage, 'bottom-right', 60, 20, 20, 1200, 800);
```

### Supported `$options` Keys

- **Geometry & Cropping**:
  - `cropping` (`bool|string|array|Alignment`, default `'center'`):
    - `true` or `'center'`: Applies ProcessWire focal point + zoom (`applyFocusCrop()`) when `$image->hasFocus` or `$options['focus']` is present; otherwise center-covers.
    - `false`: Scales proportionally (`scale()` / `scaleDown()`) without cropping.
    - `'contain'`: Fits inside target box and pads remaining canvas with `background` (`contain()` / `containDown()`).
    - Compass / `Alignment`: `'north'`, `'south'`, `'east'`, `'west'`, `'northwest'`, `'northeast'`, `'southwest'`, `'southeast'`, `'top-left'`, `'bottom-right'`, or `Alignment` enum (overrides focal point and appends `-north`, etc.).
    - Coordinate string or array: `'x100y200'`, `[100, 200]`, or `['25%', '50%']` (appends `-c100x200`).
  - `upscaling` (`bool`), `contain` (`bool`), `background` (`string`), `fillTransparent` (`bool`), `trim` (`bool|int`, `0..100`), `focus` (`array{top?: float, left?: float, zoom?: int}`).
- **Orientation & Effects**:
  - `rotate` (`float`), `flip` (`'h'|'v'|Direction`).
  - `sharpening` (`'none'|'soft'|'medium'|'strong'`, default `'soft'`) or explicit numeric `sharpen` (`int`, `0..100`, overrides `sharpening` preset).
  - `blur` (`int`, `0..100`), `brightness` (`int`, `-100..100`), `contrast` (`int`, `-100..100`), `gamma` (`float`, `0.1..10.0`), `pixelate` (`int`, `>=1`), `colorize` (`string|array`), `grayscale` (`bool`), `invert` (`bool`), `reduceColors` (`int`, `1..256`, supported across Vips, Imagick, and GD).
- **Watermark (`insert`) & Custom Modifiers (`modifiers`)**:
  - `insert` (`Pageimage|string|array{element: string|Pageimage, position?: string, offset_x?: int, offset_y?: int, opacity?: int}`).
  - `modifiers` (`array`): `ModifierInterface` instances, class-strings, or typed closures `static fn(ImageInterface $img): ImageInterface => ...`.
- **Encoding & Delivery**:
  - `format` (`'webp'|'avif'|'jpg'|'jpeg'|'png'|'gif'`), `quality` (`int`, `0..100`), `strip` (`bool`), `progressive` (`bool`), `interlaced` (`bool`), `delayed` (`bool`), `suffix` (`string|string[]`), `hidpi` (`bool`).

### Responsive HTML, LQIP, Color & Perceptual Hash Hooks

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */

// Render <picture> (with AVIF/WebP <source> when avifAdd/webpAdd enabled) or <img>
echo $image->render('landscape', [
	'sizes' => '(max-width: 1200px) 100vw, 1200px',
	'loading' => 'eager',   // 'eager' | 'lazy' | bool
	'decoding' => 'async',  // 'sync' | 'async' | 'auto'
	'isFirst' => true,      // Adds fetchpriority="high"
	'class' => 'hero-img',
]);

$attrs = $image->attrs(800, 600, ['isFirst' => true]);
$srcset = $image->srcset(800, 600, ['format' => 'webp']);
$lqip = $image->lqip(); // 24px blurred WebP Base64 Data URI ({basename}.lqip.webp)

// ColorExtractor hooks ({basename}.colors.json)
$dominant = $image->dominantColor(true); // '#2b3a4a' (pass false to omit '#')
$palette  = $image->palette(6, true);    // ['#2b3a4a', '#d9822b', ...]
$theme    = $image->colorTheme(true);    // ['vibrant' => '#...', 'muted' => '#...', 'darkVibrant' => '#...', 'darkMuted' => '#...', 'lightVibrant' => '#...', 'lightMuted' => '#...']

// Perceptual ImageHash hooks ({basename}.hashes.json; strategies: 'dhash' [default, 16 hex], 'phash' [16 hex], 'ahash' [16 hex], 'bhash' [64 hex])
$hash     = $image->imageHash('dhash');                        // '8f9e9d8b0f0f1f07'
$distance = $image->hashDistance($otherImageOrHex, 'dhash');   // int Hamming distance (0 = identical)
$similar  = $image->isSimilar($otherImageOrHex, 5, 'dhash');   // bool (true when distance <= 5)
```

## 3. Queue Management & Cache Cleanup (Module API, Page Hooks & CLI)

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var InterventionImage $module */
$module = $this->wire()->modules->get('InterventionImage');

// 1. Clear all variations and sidecars (.lqip.webp, .colors.json, .hashes.json, .queue) site-wide or per-page
$stats = $module->clearVariations();
$pageStats = $module->clearVariations($page, ['variations' => true, 'lqip' => true, 'hashes' => true]);
$hookStats = $page->clearImageVariations(['dryRun' => false]);

// 2. Inspect or process pending .queue sidecars programmatically
$inspection = $module->inspectQueue($page, 50);  // ['total' => int, 'items' => [...]]
$queueResult = $module->processQueue($page, 50); // ['processed' => int, 'failed' => int, 'remaining' => int, 'errors' => [...]]

// 3. Pageimage::removeVariations() removes variations + .lqip.webp, .colors.json, .hashes.json, and .queue for that image
$image->removeVariations();
```

### Console CLI Commands (`vendor/bin/wire`)

- `php vendor/bin/wire image:queue [--status] [--limit=50] [--page=<id|path>] [--clear]`: Inspect, process, or clear pending `.queue` sidecar files.
- `php vendor/bin/wire image:clear [--page=<id|path>] [--variations-only] [--lqip-only] [--colors-only] [--hashes-only] [--queue-only] [--dry-run]`: Delete generated variations and sidecar caches globally or for a single page.
