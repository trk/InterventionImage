# Intervention Image Engine for ProcessWire

A high-performance responsive image variation, visual color analysis, and perceptual fingerprinting engine for ProcessWire powered by [Intervention Image v4.4](https://image.intervention.io/v4). It replaces ProcessWire's default frontend image sizing engine with `libvips` (`Vips`), `Imagick`, and `GD` driver auto-detection, delayed on-demand `.queue` rendering, pixel-accurate focal-point + zoom cropping, next-generation `AVIF` / `WebP` `<picture>` output, Base64 `LQIP` placeholders, `ColorExtractor` palettes, and `Intervention\ImageHash` similarity matching.

## Requirements

- **PHP:** `>= 8.3` (tested on PHP `8.5`)
- **ProcessWire:** `>= 3.0.271`
- **PHP Extensions:** `ext-fileinfo`, plus at least one image processing backend:
  - **Vips (`libvips`):** `ext-ffi` (`ffi.enable=true` for Web/FPM or `ffi.enable=preload` for CLI) + system `libvips` (`intervention/image-driver-vips: ^4.2`)
  - **Imagick:** `ext-imagick`
  - **GD:** `ext-gd`

## Installation

Install the module dependencies via Composer and enable the module using `vendor/bin/wire`:

```bash
cd site/modules/InterventionImage && composer install
php vendor/bin/wire module:install InterventionImage
```

## Quick Start

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Page $page */
/** @var Pageimage|null $image */
$image = $page->get('images')?->first();

if ($image instanceof Pageimage) {
	// 1. Render a responsive <picture> element using the 'landscape' preset
	echo $image->render('landscape', [
		'loading' => 'eager',
		'isFirst' => true,
		'class' => 'hero-image',
	]);

	// 2. Generate a specific WebP variation with focal-point crop and medium sharpening
	$thumb = $image->size(800, 600, [
		'format' => 'webp',
		'quality' => 85,
		'sharpening' => 'medium',
	]);
	echo $thumb->url;

	// 3. Extract dominant hex color and perceptual dHash fingerprint
	$dominantHex = $image->dominantColor();
	$dhash = $image->imageHash('dhash');
}
```

## Configuration

Configure the module in **Modules > Configure > InterventionImage**:

| Setting | Type | Default | Description |
|---|---|---|---|
| `driver` | `string` | `'auto'` | Image driver selection: `'auto'` (`Vips -> Imagick -> GD` via runtime `checkHealth()`), `'vips'`, `'imagick'`, or `'gd'`. |
| `options` | `list<string>` | `['delayed', 'lqip', 'lazyload', 'inlineLazyload', 'upscale', 'strip', 'progressive', 'avifAdd', 'webpAdd']` | Feature flags for delayed `.queue` rendering, LQIP generation, fade-in lazyloading, metadata stripping, progressive encoding, and `<picture>` `<source>` tags. |
| `quality` | `int` | `90` | Default compression quality (`0..100`) for generated variations. |
| `outputFormat` | `string` | `'original'` | Default variation format: `'original'`, `'webpOnly'`, or `'avifOnly'`. |
| `breakpoints` | `string` | `540=s\|Small` ... `1200=+l\|Large` | Multiline responsive breakpoints (`value=key\|Label`; prefix `+` marks the default breakpoint). |
| `aspectRatios` | `string` | `1:1=square`, `16:9=+landscape`, `21:9=wide`, `4:5=portrait` | Multiline aspect ratio presets registered into `$config->imageSizes`. |
| `columnWidths` | `string` | `'1-1, 1-2, 1-3, 1-4, 2-3, 3-4'` | Grid column fractions combined with aspect ratios (e.g. `landscape-1-2`). |
| `factors` | `string` | `'0.5, 1, 1.5, 2'` | Scaling multipliers used by `$image->srcset()`. |

## Hooks & Events

### `Pageimage` & `Page` Hook Methods

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Pageimage $image */
/** @var Page $page */

// Geometry, Crop, Contain & Watermark
$sized = $image->size(800, 600, [
	'cropping' => 'center',     // true|'center'|false|'contain'|'north'|'southeast'|'x100y200'|[100, 200]
	'trim' => 10,               // Trim uniform borders (0..100)
	'sharpening' => 'medium',   // 'none'|'soft'|'medium'|'strong' (or numeric 'sharpen' => 0..100)
	'blur' => 5,                // Gaussian blur (0..100)
	'brightness' => 10,         // Brightness (-100..100)
	'contrast' => 10,           // Contrast (-100..100)
	'gamma' => 1.2,             // Gamma (0.1..10.0)
	'colorize' => [20, -10, 0], // RGB channel shift (-100..100)
	'grayscale' => true,        // Grayscale conversion
	'invert' => false,          // Color inversion
	'pixelate' => 4,            // Pixelation block size
	'reduceColors' => 64,       // Palette reduction (1..256)
	'modifiers' => [            // Custom Intervention Image v4.4 ModifierInterface or typed closures
		static fn(\Intervention\Image\Interfaces\ImageInterface $img): \Intervention\Image\Interfaces\ImageInterface => $img->sharpen(15),
	],
]);

$cropped = $image->crop(100, 50, 600, 400, ['format' => 'webp']);
$contained = $image->contain(800, 600, 'ffffff');
$watermarked = $image->watermark($logoPageimage, 'bottom-right', 50, 15, 15, 1200, 800);

// Responsive HTML & LQIP
$html = $image->render('landscape', ['loading' => 'lazy']);
$attrs = $image->attrs(800, 600, ['decoding' => 'async']);
$srcset = $image->srcset('landscape');
$lqipDataUri = $image->lqip();

// Visual Color Analysis (cached in {basename}.colors.json)
$dominant = $image->dominantColor(true);
$palette = $image->palette(6, true);
$theme = $image->colorTheme(true);

// Perceptual ImageHash & Similarity (cached in {basename}.hashes.json)
// Strategies: 'dhash' (default, 64-bit), 'phash' (64-bit), 'ahash' (64-bit), 'bhash' (256-bit)
$hash = $image->imageHash('dhash');
$distance = $image->hashDistance($otherPageimage, 'dhash');
$isDuplicate = $image->isSimilar($otherPageimage, 5, 'dhash');

// Variation & Sidecar Cleanup
$pageStats = $page->clearImageVariations(['dryRun' => false]);
```

### Module Programmatic API

See [API.md](API.md) for the complete reference of `InterventionImage` public methods (`manager()`, `calculate()`, `inspectQueue()`, `processQueue()`, `processQueueFile()`, `clearVariations()`, `resolveTargetDirectory()`), and [GLOSSARY.md](GLOSSARY.md) for domain terminology.

## CLI / MCP Integration

When `trk/processwire-console` is installed, the module registers `image:queue` and `image:clear`:

```bash
# Inspect pending delayed (.queue) variations globally or for a specific page
php vendor/bin/wire image:queue --status
php vendor/bin/wire image:queue --status --page=1024

# Process up to 50 pending .queue variations in batch (ideal for cron / libvips CLI workers)
php vendor/bin/wire image:queue --limit=50
php vendor/bin/wire image:queue --limit=50 --page=/products/

# Clear pending .queue files without generating variations
php vendor/bin/wire image:queue --clear

# Preview or delete variations and sidecar caches (.lqip.webp, .colors.json, .hashes.json, .queue)
php vendor/bin/wire image:clear --dry-run
php vendor/bin/wire image:clear --page=1024
php vendor/bin/wire image:clear --variations-only
php vendor/bin/wire image:clear --lqip-only
php vendor/bin/wire image:clear --colors-only
php vendor/bin/wire image:clear --hashes-only
php vendor/bin/wire image:clear --queue-only

# Run the module's automated Pest test suite
php vendor/bin/wire test site/modules/InterventionImage/tests
```

## Security & Permissions

- **Selector Sanitization:** Page path filters passed to `clearVariations()`, `inspectQueue()`, `processQueue()`, or CLI `--page` options are sanitized via `$sanitizer->selectorValue()` inside `resolveTargetDirectory()`.
- **Atomic Sidecar Persistence:** All sidecar caches (`.queue`, `.colors.json`, `.hashes.json`) are written through `$files->filePutContents(..., LOCK_EX)` to prevent race conditions during concurrent HTTP or CLI requests.
- **EXIF / Metadata Stripping:** When `strip` is enabled (default), EXIF/GPS metadata is stripped from generated variations during encoding.
