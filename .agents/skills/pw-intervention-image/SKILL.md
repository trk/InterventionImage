---
name: pw-intervention-image
description: "Use when resizing, cropping, containing, watermarking, extracting dominant colors/palettes/Vibrant-Muted themes/LQIP, or rendering responsive HTML/srcset for ProcessWire Pageimage objects via InterventionImage (Intervention Image v4.4), or when running CLI queue and variation cleanup tasks."
risk: safe
source: processwire-boost
metadata:
  version: 110
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
---

# ProcessWire InterventionImage (v4.4)

Integrate Intervention Image v4.4 into ProcessWire's native `Pageimage` variation pipeline, responsive `<picture>` / `srcset` renderer, `ColorExtractor` engine, and CLI queue runner.

## When to Use

- Resizing, cropping, containing/padding, or watermarking `Pageimage` instances on the frontend.
- Rendering responsive `<img>` or `<picture>` elements with AVIF/WebP `<source>` sets, factor-based `srcset`, and inline Base64 LQIP placeholders.
- Extracting dominant hex colors, palettes, or `VibrantMuted` color themes (`vibrant`, `muted`, `darkVibrant`, `darkMuted`, `lightVibrant`, `lightMuted`) from a `Pageimage`.
- Processing delayed `.queue` image variations or cleaning up generated variations/caches via `vendor/bin/wire`.

## Core Concepts

### 1. Pageimage Variation Hooks

Operate exclusively through `Pageimage` hooks so variations use ProcessWire's deterministic suffix scheme (`createSuffix()`) and are automatically deleted when the parent `Pagefile` is removed:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */

// 1. Cover / crop to exact dimensions (uses ProcessWire focal point & zoom automatically)
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

// 4. Explicit pixel-coordinate crop: crop(int $x, int $y, int|string $width, int|array $height = 0, array $options = [])
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
| **Geometry & Crop** | `cropping` (`bool\|string\|array\|Alignment`, default `'center'`), `upscaling` (`bool`), `contain` (`bool`), `focus` (`array{top?: float, left?: float, zoom?: int}`) | `cropping: true\|'center'` uses focal point + zoom when present; `false` scales proportionally; `'contain'` pads to exact box; compass strings (`'north'`, `'southeast'`), `'x100y200'`, or `['25%', '50%']` align crop. |
| **Canvas & Trim** | `background` (`string`, e.g. `'ffffff'`), `fillTransparent` (`bool`), `trim` (`bool\|int`, `0..100`) | `trim` strips uniform borders before sizing. `fillTransparent: true` composites transparent pixels over `background`. |
| **Orientation** | `rotate` (`float`), `flip` (`'h'\|'v'\|Direction`) | Rotates by degrees and reflects horizontally or vertically prior to sizing. |
| **Filters** | `sharpening` (`'none'\|'soft'\|'medium'\|'strong'`), `sharpen` (`0..100`), `blur` (`0..100`), `brightness` (`-100..100`), `contrast` (`-100..100`), `gamma` (`0.1..10.0`), `pixelate` (`int`), `colorize` (`'r,g,b'\|int[]`), `grayscale` (`bool`), `invert` (`bool`), `reduceColors` (`1..256`) | Executed in deterministic order after geometry changes. `reduceColors` preserves TrueColor buffers for WebP/AVIF on GD. |
| **Watermark** | `insert` (`Pageimage\|string\|array`) | Array keys: `element` (path or `Pageimage`), `position` (`'top-left'`, `'bottom-right'`, etc.), `offset_x`, `offset_y`, `opacity` (`0..100`). |
| **Custom Modifiers** | `modifiers` (`array`) | Accepts Intervention v4.4 `ModifierInterface` instances, `ModifierInterface` class-strings, or closures `fn(ImageInterface $img): ImageInterface`. |
| **Encoding & Queue** | `format` (`'webp'\|'avif'\|'jpg'\|'png'\|'gif'`), `quality` (`0..100`), `strip` (`bool`), `progressive` (`bool`), `interlaced` (`bool`), `delayed` (`bool`), `suffix` (`string\|string[]`), `hidpi` (`bool`) | `delayed: true` writes a `.queue` sidecar file and renders on first request (`handlePageNotFound`) or via `wire image:queue`. |

### 3. Responsive Markup, LQIP & ColorExtractor Hooks

Use built-in markup and color hooks instead of manual loops:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */

// Render <picture> (with AVIF/WebP <source> when avifAdd/webpAdd enabled) or <img>
echo $image->render('landscape', [
	'sizes' => '(max-width: 1200px) 100vw, 1200px',
	'loading' => 'eager',   // 'eager' | 'lazy' | bool
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
```

### 4. Variation Cleanup (PHP Hooks & CLI Commands)

Clean up variations and sidecar caches (`.lqip.webp`, `.colors.json`, `.queue`) programmatically or via `processwire-console`:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

// Clear all variations and caches for a specific Page
$stats = $page->clearImageVariations();

// Clear site-wide (or pass Page|int|string as first argument)
/** @var InterventionImage $imgEngine */
$imgEngine = $modules->get('InterventionImage');
$stats = $imgEngine->clearVariations(null, ['variations' => true, 'lqip' => true, 'dryRun' => false]);

// Native Pageimage::removeVariations() also deletes .lqip.webp, .colors.json, and .queue for that image
$image->removeVariations();
```

```bash
# Process pending .queue variation files in site/assets/files/ (or for a single page)
php vendor/bin/wire image:queue --status
php vendor/bin/wire image:queue --limit=50
php vendor/bin/wire image:queue --page=1024
php vendor/bin/wire image:queue --clear

# Preview or remove generated variations, .lqip.webp, .colors.json, and .queue files
php vendor/bin/wire image:clear --dry-run
php vendor/bin/wire image:clear --page=1024
php vendor/bin/wire image:clear --variations-only
php vendor/bin/wire image:clear --lqip-only
php vendor/bin/wire image:clear --colors-only
```

## Anti-Patterns & Loophole Closing

- ❌ **NEVER use Intervention Image v2/v3 methods (`$manager->read()`, `Image::make()`, `greyscale()`, `flop()`, `fit()`, `resizeCanvas()`, `pickColor()`).**
  - ✅ This module runs exclusively on **Intervention Image v4.4**: `decodePath()` / `decode()`, `grayscale()`, `flip()`, `cover()` / `coverDown()`, `contain()` / `containDown()`, `colorAt()`, and `encodeUsingFormat(Format::WEBP, quality: 85)`.
- ❌ **NEVER pass a raw `'800x600'` string as the first argument to `$image->size('800x600')`.**
  - ✅ ProcessWire's non-hookable `Pageimage::size()` wrapper treats any non-numeric string as a named preset and throws `Unknown image size` if it is not in `$config->imageSizes`. Pass integer dimensions `$image->size(800, 600, $options)` or a configured preset key `$image->size('landscape', $options)`.
- ❌ **NEVER pass `crop($width, $height)` expecting the first two arguments of `Pageimage::crop()` to be dimensions.**
  - ✅ `Pageimage::crop($x, $y, $width, $height = 0, $options = [])` takes pixel coordinates `($x, $y)` first, matching ProcessWire's native `Pageimage::crop()` contract. For alignment-based cropping by dimensions, call `$image->size($width, $height, ['cropping' => 'north'])`.
- ❌ **NEVER build manual `<picture>` loops by calling `$image->size()` repeatedly in templates when responsive markup is needed.**
  - ✅ Call `$image->render($width, $height, $options)` or `$image->attrs($width, $height, $options)` / `$image->srcset($width, $height, $options)`.
- ❌ **NEVER recompute image colors on every request with raw `imagecolorat()` / GD loops.**
  - ✅ Call `$image->dominantColor()`, `$image->palette($limit)`, or `$image->colorTheme()`, which use Intervention Image v4.4 `ColorExtractor` and cache results in `{basename}.colors.json`.

## Related Skills

- `pw-pages` — Retrieving pages and `Pageimages` fields via ProcessWire selectors.
- `pw-module-development` — Extending ProcessWire modules and hooks with strict typing.
- `pw-test` — Writing and running Pest tests for `InterventionImage` hooks via `vendor/bin/wire test`.
