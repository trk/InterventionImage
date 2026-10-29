---
description: "Use when resizing, cropping, containing, watermarking, extracting dominant colors/palettes/Vibrant-Muted themes/LQIP, or rendering responsive HTML/srcset for ProcessWire Pageimage objects via InterventionImage (Intervention Image v4.4), or when running CLI queue and variation cleanup tasks."
---

# ProcessWire InterventionImage Module Guidelines

`InterventionImage` replaces ProcessWire's default sizing engine on the frontend with **Intervention Image v4.4** (`intervention/image: ^4.4`, `intervention/gif: ^5.0`), adding delayed on-demand `.queue` rendering, pixel-accurate focal-point + zoom cropping, responsive `<picture>` / `srcset` generation, Base64 LQIP placeholders, and `ColorExtractor` metadata (`dominantColor`, `palette`, `colorTheme`).

## 1. Core Principles

- **Pageimage-first**: Always call `Pageimage` hooks (`$image->size()`, `$image->crop()`, `$image->contain()`, `$image->watermark()`, `$image->render()`, `$image->srcset()`, `$image->attrs()`, `$image->lqip()`, `$image->dominantColor()`, `$image->palette()`, `$image->colorTheme()`) so variations use deterministic ProcessWire suffixes (`createSuffix()`) and sidecar files (`*.queue`, `*.lqip.webp`, `*.colors.json`) that are automatically cleaned up when the parent `Pagefile` is deleted.
- **Intervention Image v4.4 only**: All legacy Intervention v2/v3 calls are removed. When working with the underlying manager (`$modules->get('InterventionImage')->manager()`), use v4.4 APIs exclusively.

## 2. Intervention Image v3 -> v4.4 Translation Table

| Legacy v2/v3 (Do Not Emit) | Intervention Image v4.4 (Always Emit) | `InterventionImage` Option / Hook |
|---|---|---|
| `$manager->read($path)` / `Image::make($path)` | `$manager->decodePath($path)` / `$manager->decode($input)` | Handled by `InterventionImage::create()` |
| `$img->greyscale()` | `$img->grayscale()` | `['grayscale' => true]` |
| `$img->flop()` | `$img->flip(Direction::HORIZONTAL)` | `['flip' => 'h']` (`'h'`, `'v'`, or `Direction` enum) |
| `$img->fit($w, $h)` | `$img->cover($w, $h, $alignment)` / `$img->coverDown(...)` | `$image->size($w, $h, ['cropping' => 'center'])` |
| `$img->resizeCanvas($w, $h)` | `$img->contain($w, $h, $bg, $align)` / `$img->containDown(...)` | `$image->contain($w, $h, 'ffffff')` or `['cropping' => 'contain', 'background' => 'ffffff']` |
| `$img->insert($src, 'bottom-right', 15, 15)` | `$img->insert($src, 15, 15, Alignment::BOTTOM_RIGHT, 0.5)` | `$image->watermark($src, 'bottom-right', 50, 15, 15)` |
| `$img->encodeByExtension('webp', quality: 85)` | `$img->encodeUsingFormat(Format::WEBP, quality: 85)` | `['format' => 'webp', 'quality' => 85]` |
| `$img->toWebp(20)->toDataUri()` | `$img->encodeUsingFormat(Format::WEBP, quality: 20)->toDataUri()` | `$image->lqip()` |

## 3. Pageimage Hook Signatures & Usage

### Variation Hooks (`Pageimage` return)

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */

// 1. Standard or preset-based resize/cover (auto-applies ProcessWire focal point & zoom when set)
$thumb = $image->size(600, 400, ['quality' => 85, 'format' => 'webp']);
$preset = $image->size('landscape-1-2', ['sharpening' => 'medium']);

// 2. Proportional scale without cropping
$scaled = $image->size(1200, 0, ['cropping' => false, 'upscaling' => false]);

// 3. Explicit pixel-coordinate crop: crop($x, $y, $width, $height = 0, $options = [])
$cutout = $image->crop(100, 50, 600, 400, ['format' => 'webp']);

// 4. Fit & pad inside exact box without cropping: contain($width, $height = null, $options = [])
// Third argument accepts a hex background string or an options array
$padded = $image->contain(800, 600, 'ffffff');

// 5. Watermark overlay: watermark($watermark, $position = Alignment::BOTTOM_RIGHT, $opacity = 50, $offsetX = 15, $offsetY = 15, $width = null, $height = null, $options = [])
$branded = $image->watermark($logoPageimage, 'bottom-right', 60, 20, 20, 1200, 800);
```

### Supported `$options` Keys in `size()` / `crop()` / `contain()` / `render()`

- **Geometry & Cropping**:
  - `cropping` (`bool|string|array|Alignment`, default `'center'`):
    - `true` or `'center'`: Uses ProcessWire focal point + zoom (`applyFocusCrop()`) when `$image->hasFocus` or `$options['focus']` is present; otherwise center-covers.
    - `false`: Scales proportionally (`scale()` / `scaleDown()`) without cropping.
    - `'contain'`: Fits inside target box and pads remaining canvas with `background` (`contain()` / `containDown()`).
    - Compass / `Alignment`: `'north'`, `'south'`, `'east'`, `'west'`, `'northwest'`, `'northeast'`, `'southwest'`, `'southeast'`, `'top-left'`, `'bottom-right'`, or `Alignment` enum.
    - Coordinate string or array: `'x100y200'`, `[100, 200]`, or `['25%', '50%']`.
  - `upscaling` (`bool`): Enlarges images smaller than target box when `true`.
  - `contain` (`bool`): Alias for `cropping => 'contain'`.
  - `background` (`string`): Hex color (e.g. `'ffffff'`) for `contain`, `rotate`, `reduceColors`, or transparent JPEG fill.
  - `fillTransparent` (`bool`): Composites transparent pixels over `background` before encoding.
  - `trim` (`bool|int`): Trims uniform border pixels within tolerance (`0..100`) before resizing.
  - `focus` (`array{top?: float, left?: float, zoom?: int}`): Overrides ProcessWire focal point percentages and zoom (`0..90`).
- **Orientation & Effects**:
  - `rotate` (`float`): Rotation angle in degrees.
  - `flip` (`'h'|'v'|Direction`): Horizontal or vertical reflection.
  - `sharpening` (`'none'|'soft'|'medium'|'strong'`, default `'soft'`).
  - `sharpen` (`int`, `0..100`), `blur` (`int`, `0..100`), `brightness` (`int`, `-100..100`), `contrast` (`int`, `-100..100`), `gamma` (`float`, `0.1..10.0`), `pixelate` (`int`, `>=1`).
  - `colorize` (`string|array`, e.g. `'20,-10,0'`, `[20, -10, 0]`, or `['red' => 20, 'green' => -10, 'blue' => 0]`).
  - `grayscale` (`bool`), `invert` (`bool`), `reduceColors` (`int`, `1..256`, preserves TrueColor buffer for WebP/AVIF on GD).
- **Watermark (`insert`) & Custom Modifiers (`modifiers`)**:
  - `insert` (`Pageimage|string|array{element: string|Pageimage, position?: string, offset_x?: int, offset_y?: int, opacity?: int}`).
  - `modifiers` (`array`): Custom `Intervention\Image\Interfaces\ModifierInterface` instances, `ModifierInterface` class-strings, or closures `fn(ImageInterface $img): ImageInterface`.
- **Encoding & Delivery**:
  - `format` (`'webp'|'avif'|'jpg'|'jpeg'|'png'|'gif'`), `quality` (`int`, `0..100`).
  - `strip` (`bool`, strips EXIF/metadata), `progressive` (`bool`), `interlaced` (`bool`).
  - `delayed` (`bool`): Writes a `.queue` sidecar JSON file instead of rendering synchronously; rendered on first HTTP request via `handlePageNotFound()` or in batch via `php vendor/bin/wire image:queue`.
  - `suffix` (`string|string[]`), `hidpi` (`bool`).

### Responsive HTML, LQIP & Color Analysis Hooks

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */

// Render <picture> (with AVIF/WebP <source> when avifAdd/webpAdd enabled) or <img>
echo $image->render('landscape', [
	'sizes' => '(max-width: 1200px) 100vw, 1200px',
	'loading' => 'eager',   // 'eager' | 'lazy' | bool
	'isFirst' => true,      // Adds fetchpriority="high"
	'class' => 'hero-img',
]);

// Return attribute array: ['src', 'width', 'height', 'alt', 'srcset', 'sources', 'sizes', 'class', 'style', 'loading', 'decoding', 'fetchpriority']
$attrs = $image->attrs(800, 600, ['isFirst' => true]);

// Return factor-based srcset string (uses module factors: 0.5, 1, 1.5, 2)
$srcset = $image->srcset(800, 600, ['format' => 'webp']);

// Compact 24px blurred WebP Base64 Data URI (cached in {basename}.lqip.webp)
$lqip = $image->lqip();

// ColorExtractor hooks (cached in {basename}.colors.json)
$dominant = $image->dominantColor(true); // '#2b3a4a' (pass false to omit '#')
$palette  = $image->palette(6, true);    // ['#2b3a4a', '#d9822b', ...]
$theme    = $image->colorTheme(true);    // ['vibrant' => '#...', 'muted' => '#...', 'darkVibrant' => '#...', 'darkMuted' => '#...', 'lightVibrant' => '#...', 'lightMuted' => '#...']
```

## 4. Variation & Cache Cleanup (Module API, Page Hooks & CLI)

### PHP / Module & Page Hooks

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var InterventionImage $module */
$module = $modules->get('InterventionImage');

// 1. Clear ALL variations, .lqip.webp, .colors.json, and .queue files site-wide
$stats = $module->clearVariations();

// 2. Clear variations for a specific Page (accepts Page object, Page ID, or path)
$stats = $module->clearVariations($page, ['variations' => true, 'lqip' => true]);

// 3. Page hook: clear all image variations & sidecar caches on a Page instance
$stats = $page->clearImageVariations(['dryRun' => false]);

// 4. Pageimage hook: removeVariations() also removes .lqip.webp, .colors.json, and .queue for that image
$image->removeVariations();
```

### Console CLI Commands (`vendor/bin/wire`)

- `php vendor/bin/wire image:queue [--status] [--limit=50] [--page=<id|path>] [--clear]`: Process, inspect, or clear pending `.queue` sidecar files.
- `php vendor/bin/wire image:clear [--page=<id|path>] [--variations-only] [--lqip-only] [--colors-only] [--queue-only] [--dry-run]`: Delete generated variations, `.lqip.webp` placeholders, `.colors.json` metadata, and `.queue` files globally or for a given page.
