---
name: pw-intervention-image
description: "Use when resizing, cropping, containing, watermarking, extracting dominant colors/palettes/Vibrant-Muted themes/LQIP, computing perceptual image hashes (dhash, phash, ahash, bhash) or similarity distance, or rendering responsive HTML/srcset for ProcessWire Pageimage objects via InterventionImage (Intervention Image v4.4 with Vips/Imagick/GD), or when running CLI queue and variation cleanup tasks."
risk: safe
source: processwire-boost
metadata:
  version: 120
  intervention-image-version: "^4.4"
  processwire-version: ">=3.0.271"
  php-version: ">=8.3"
  triggers:
    - InterventionImage
    - Pageimage
    - image->size
    - image->crop
    - image->contain
    - image->watermark
    - image->render
    - image->srcset
    - image->attrs
    - image->lqip
    - image->dominantColor
    - image->palette
    - image->colorTheme
    - image->imageHash
    - image->hashDistance
    - image->isSimilar
---

# ProcessWire InterventionImage (v4.4)

Integrate Intervention Image v4.4 (`Vips`, `Imagick`, `GD`), `ColorExtractor`, and `Intervention\ImageHash` into ProcessWire's native `Pageimage` variation pipeline, responsive `<picture>` / `srcset` renderer, and CLI queue runner.

## Context Pointers

- **API Reference (`site/modules/InterventionImage/API.md`)**: Read when calling `InterventionImage` module methods (`manager()`, `calculate()`, `inspectQueue()`, `processQueue()`, `clearVariations()`) or checking exact `Pageimage` hook return signatures.
- **Domain Glossary (`site/modules/InterventionImage/GLOSSARY.md`)**: Read when naming variables, array keys, or docblocks involving `Variation`, `Delayed Queue`, `LQIP`, `Color Metadata`, `Perceptual Hash`, `Hamming Distance`, or `Sidecar Cache`.
- **ADR-0001 Driver Fallback (`site/modules/InterventionImage/docs/adr/0001-vips-driver-health-fallback.md`)**: Read when modifying driver selection, `isDriverHealthy()`, or `ffi.enable=preload` SAPI diagnostics.
- **ADR-0002 Hash Sidecar (`site/modules/InterventionImage/docs/adr/0002-perceptual-hash-sidecar-cache.md`)**: Read when modifying `.hashes.json` persistence, hash strategies (`dhash`, `phash`, `ahash`, `bhash`), or cache invalidation.

## Core Concepts

### 1. Pageimage Variation Hooks

Operate through `Pageimage` hooks so variations use ProcessWire's deterministic suffix scheme (`createSuffix()`) and are automatically deleted when the parent `Pagefile` is removed:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */

// 1. Cover / crop to exact dimensions (uses ProcessWire focal point & zoom automatically on center crops)
$cover = $image->size(800, 600, [
	'quality' => 85,
	'format' => 'webp',
	'sharpening' => 'medium',
]);

// 2. Named preset from $config->imageSizes (e.g. 'square', 'landscape', 'portrait', 'landscape-1-2')
$preset = $image->size('landscape-1-2', ['format' => 'webp']);

// 3. Proportional scale without cropping
$scaled = $image->size(1200, 0, [
	'cropping' => false,
	'upscaling' => false,
]);

// 4. Explicit pixel-coordinate crop: crop(int $x, int $y, int|string $width, int|string|array $height = 0, array $options = [])
$cropped = $image->crop(120, 80, 600, 400, ['format' => 'avif']);

// 5. Fit & pad inside exact box without cropping: contain($width, $height = null, $optionsOrBgHex = [])
$contained = $image->contain(800, 600, 'ffffff');

// 6. Watermark overlay: watermark($watermark, $position = Alignment::BOTTOM_RIGHT, $opacity = 50, $offsetX = 15, $offsetY = 15, $width = null, $height = null, $options = [])
$watermarked = $image->watermark($logoPageimage, 'bottom-right', 60, 20, 20, 1200, 800, [
	'format' => 'webp',
]);
```

### 2. Complete `$options` Reference

Pass any combination of these keys to `$image->size()`, `$image->crop()`, `$image->contain()`, `$image->watermark()`, `$image->srcset()`, `$image->attrs()`, or `$image->render()`:

| Category | Option Keys & Types | Behavior |
|---|---|---|
| **Geometry & Crop** | `cropping` (`bool\|string\|array\|Alignment`, default `'center'`), `upscaling` (`bool`), `contain` (`bool`), `focus` (`array{top?: float, left?: float, zoom?: int}`) | `cropping: true\|'center'` uses focal point + zoom when present; `false` scales proportionally; `'contain'` pads to exact box; compass strings (`'north'`, `'southeast'`), `'x100y200'`, or `[100, 200]` / `['25%', '50%']` align crop and override focal point. |
| **Canvas & Trim** | `background` (`string`, e.g. `'ffffff'`), `fillTransparent` (`bool`), `trim` (`bool\|int`, `0..100`) | `trim` strips uniform borders before sizing. `fillTransparent: true` composites transparent pixels over `background`. |
| **Orientation** | `rotate` (`float`), `flip` (`'h'\|'v'\|Direction`) | Rotates by degrees and reflects horizontally or vertically prior to sizing. |
| **Filters** | `sharpening` (`'none'\|'soft'\|'medium'\|'strong'`), `sharpen` (`0..100`, overrides `sharpening`), `blur` (`0..100`), `brightness` (`-100..100`), `contrast` (`-100..100`), `gamma` (`0.1..10.0`), `pixelate` (`int`), `colorize` (`'r,g,b'\|int[]`), `grayscale` (`bool`), `invert` (`bool`), `reduceColors` (`1..256`) | Executed in deterministic order after geometry changes. `reduceColors` supports Vips, Imagick, and GD (preserving TrueColor buffers for WebP/AVIF on GD). |
| **Watermark** | `insert` (`Pageimage\|string\|array`) | Array keys: `element` (path or `Pageimage`), `position` (`'top-left'`, `'bottom-right'`, etc.), `offset_x`, `offset_y`, `opacity` (`0..100`). |
| **Custom Modifiers** | `modifiers` (`array`) | Accepts Intervention v4.4 `ModifierInterface` instances, `ModifierInterface` class-strings, or typed closures `static fn(ImageInterface $img): ImageInterface => ...`. |
| **Encoding & Queue** | `format` (`'webp'\|'avif'\|'jpg'\|'png'\|'gif'`), `quality` (`0..100`), `strip` (`bool`), `progressive` (`bool`), `interlaced` (`bool`), `delayed` (`bool`), `suffix` (`string\|string[]`), `hidpi` (`bool`) | `delayed: true` writes a `.queue` sidecar file and renders on first request (`handlePageNotFound`) or via `wire image:queue`. |

### 3. Responsive Markup, LQIP, ColorExtractor & Perceptual Hash Hooks

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
	'isFirst' => true,      // Sets fetchpriority="high"
	'class' => 'hero-banner',
]);

// Associative attribute array for custom views or HTMX components
$attrs = $image->attrs(800, 600, [
	'isFirst' => true,
	'class' => ['rounded-xl', 'shadow-lg'],
]);

// Factor-based srcset string (uses module factors: 0.5, 1, 1.5, 2)
$srcset = $image->srcset(800, 600, ['format' => 'webp']);

// Compact 24px blurred WebP Base64 Data URI (cached in {basename}.lqip.webp)
$lqipUri = $image->lqip();

// ColorExtractor hooks (cached in {basename}.colors.json)
$dominant = $image->dominantColor(true); // '#1e293b'
$palette  = $image->palette(6, true);    // ['#1e293b', '#38bdf8', ...]
$theme    = $image->colorTheme(true);    // ['vibrant' => '#...', 'muted' => '#...', 'darkVibrant' => '#...', 'darkMuted' => '#...', 'lightVibrant' => '#...', 'lightMuted' => '#...']

// Perceptual ImageHash hooks (cached in {basename}.hashes.json; strategies: 'dhash' [default, 16 hex], 'phash' [16 hex], 'ahash' [16 hex], 'bhash' [64 hex])
$hash     = $image->imageHash('dhash');                      // '8f9e9d8b0f0f1f07'
$distance = $image->hashDistance($otherImageOrHex, 'dhash'); // int Hamming distance (0 = identical)
$similar  = $image->isSimilar($otherImageOrHex, 5, 'dhash'); // bool (true when distance <= 5)
```

### 4. Queue Management & Variation Cleanup (PHP Hooks & CLI Commands)

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

// Clear all variations and caches for a specific Page
$stats = $page->clearImageVariations();

// Clear site-wide (or pass Page|int|string as first argument)
/** @var InterventionImage $imgEngine */
$imgEngine = $this->wire()->modules->get('InterventionImage');
$stats = $imgEngine->clearVariations(null, ['variations' => true, 'lqip' => true, 'hashes' => true, 'dryRun' => false]);

// Inspect or process pending .queue sidecars programmatically
$inspection = $imgEngine->inspectQueue($page, 50);
$queueStats = $imgEngine->processQueue($page, 50);

// Native Pageimage::removeVariations() also deletes .lqip.webp, .colors.json, .hashes.json, and .queue for that image
$image->removeVariations();
```

```bash
# Process pending .queue variation files in site/assets/files/ (or for a single page)
php vendor/bin/wire image:queue --status
php vendor/bin/wire image:queue --limit=50
php vendor/bin/wire image:queue --page=1024
php vendor/bin/wire image:queue --clear

# Preview or remove generated variations, .lqip.webp, .colors.json, .hashes.json, and .queue files
php vendor/bin/wire image:clear --dry-run
php vendor/bin/wire image:clear --page=1024
php vendor/bin/wire image:clear --variations-only
php vendor/bin/wire image:clear --lqip-only
php vendor/bin/wire image:clear --colors-only
php vendor/bin/wire image:clear --hashes-only
php vendor/bin/wire image:clear --queue-only
```

## Target Patterns & Guardrails

- **Target v4.4 Manager & Image APIs**: Call `decodePath()` / `decode()`, `grayscale()`, `flip()`, `cover()` / `coverDown()`, `contain()` / `containDown()`, `colorAt()`, and `encodeUsingFormat(Format::WEBP, quality: 85)` (replaces legacy v2/v3 `read()`, `make()`, `greyscale()`, `flop()`, `fit()`, `resizeCanvas()`, `pickColor()`).
- **Pass integers or registered preset names to `Pageimage::size()`**: Call `$image->size(800, 600, $options)` or `$image->size('landscape', $options)` because ProcessWire's upstream `Pageimage::size()` wrapper resolves string first arguments against `$config->imageSizes`.
- **Pass pixel coordinates `($x, $y)` first to `Pageimage::crop()`**: Call `$image->crop($x, $y, $width, $height, $options)` for coordinate crops, and `$image->size($width, $height, ['cropping' => 'north'])` for compass-aligned dimension crops.
- **Delegate responsive markup and color extraction to hooks**: Call `$image->render()`, `$image->attrs()`, `$image->srcset()`, `$image->dominantColor()`, `$image->palette()`, `$image->colorTheme()`, and `$image->imageHash()` so results are cached in sidecar files.

## Related Skills

- `pw-pages` — Retrieving pages and `Pageimages` fields via ProcessWire selectors.
- `pw-module-development` — Extending ProcessWire modules and hooks with strict typing.
- `pw-test` — Writing and running Pest tests for `InterventionImage` hooks via `vendor/bin/wire test`.
