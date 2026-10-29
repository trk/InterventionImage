# Intervention Image Engine for ProcessWire

A high-performance responsive image engine for ProcessWire powered by [Intervention Image v4](https://image.intervention.io/v4). This module replaces the default ProcessWire image sizing engine with a modern, feature-rich alternative that supports **delayed rendering**, **next-gen formats (WebP/AVIF)**, and **fluid responsive sizing**.

## Features

- **Delayed Rendering**: Images are generated on-demand when requested by the browser, significantly speeding up initial page loads.
- **Modern Formats**: Native support for WebP and AVIF output with EXIF/metadata stripping (`strip`) and progressive/interlaced encoding.
- **Driver Agnostic**: Automatically selects the best available driver (Imagick or GD).
- **Responsive System**: Define centralized breakpoints, aspect ratios, and column widths.
- **Pixel-Accurate Focal Point + Zoom Cropping**: Respects ProcessWire's exact `focus()` (`top%`, `left%`, `zoom%`) coordinates as well as `Intervention\Image\Alignment` positions.
- **Color Extraction (v4.4 `ColorExtractor`)**: Native `Pageimage` hooks for `dominantColor()`, `palette()`, and `colorTheme()` (`Theme::VIBRANT_MUTED`).
- **Srcset Generation**: Automatically generates `srcset` attributes based on configurable scaling factors.

## Requirements

- ProcessWire 3.x
- PHP 8.3+
- FileInfo Extension
- **Imagick** (Recommended for AVIF) or **GD** Library

## Configuration

Go to **Modules > Configure > InterventionImage** to set up your preferences.

### Core Settings

- **Image Processing Driver**: Choose `Auto` to let the module decide, or force `Imagick`/`GD`.
- **Default Output Format**:
  - `Original`: Keeps the source format (JPG -> JPG).
  - `WebP Only`: Converts all generated images to WebP.
  - `AVIF Only`: Converts all generated images to AVIF.
- **Quality**: Global compression quality (1-100).

### Responsive Settings

The module allows you to define a responsive grid system using a simple syntax:

- **Breakpoints**: Define max-widths (e.g., `1200=+l|Large`). The `+` indicates the default breakpoint.
- **Aspect Ratios**: Define ratios like `16:9=+landscape`.
- **Column Widths**: Define grid fractions (e.g., `1-2` for 50% width).

## Usage

### 1. Standard Resizing

You can use standard ProcessWire API methods. The module intercepts them to use Intervention Image.

```php
// Returns a Pageimage object (delayed render)
$thumb = $page->image->size(800, 600);
echo $thumb->url;
```

### 2. Using Pre-defined Sizes (Recommended)

The module registers image sizes based on your configuration. You can render a complete `<img>` tag with `srcset` and `sizes` attributes automatically.

```php
// Render a 'landscape' image at the default breakpoint
echo $page->image->render('landscape');

// Render a 'portrait' image taking up half the grid width (1/2)
echo $page->image->render('portrait-1-2');
```

### 3. Custom Options

You can pass an array of options to the render method:

```php
echo $page->image->render('landscape', [
    'class' => 'img-fluid shadow', // CSS classes
    'loading' => 'eager',          // 'lazy' (default) or 'eager'
    'decoding' => 'sync',          // 'async' (default) or 'sync'
    'quality' => 90,               // Override quality
    'sharpening' => 'medium',      // 'none', 'soft', 'medium', 'strong'
]);
```

### Advanced Image Options

You can pass several other image manipulation options during generation:

```php
echo $page->image->render('landscape', [
    'trim' => 10,                 // Trim border whitespace with tolerance (0-100)
    'cropping' => 'contain',      // Fit inside box without cropping (uses contain / containDown)
    'background' => 'ffffff',     // Canvas or transparent fill color
    'blur' => 10,                 // Apply gaussian blur
    'pixelate' => 5,              // Pixelate the image
    'brightness' => 15,           // Adjust brightness
    'contrast' => 10,             // Adjust contrast
    'colorize' => '255, 0, 0',    // Or as array [255, 0, 0] / ['red' => 255]
    'grayscale' => true,          // Convert to grayscale
    'invert' => true,             // Invert colors
    'reduceColors' => 64,         // Reduce color palette (1-256)
    'strip' => true,              // Strip EXIF/metadata
    'progressive' => true,        // Progressive JPEG / interlaced PNG
    'modifiers' => [              // Custom Intervention Image v4.4 ModifierInterface or closures
        static fn(\Intervention\Image\Interfaces\ImageInterface $img) => $img->sharpen(15),
    ],
]);
```

### ProcessWire `Pageimage` Hook Methods

In addition to `size()`, `crop()`, `render()`, `attrs()`, and `srcset()`, the module attaches the following hook methods to `Pageimage`:

```php
// 1. Fit image inside dimensions without cropping (contain / containDown)
$contained = $page->image->contain(800, 600, 'ffffff');

// 2. Apply a watermark image (Pageimage or path)
$watermarked = $page->image->watermark($logoPageimage, 'bottom-right', opacity: 50, offsetX: 15, offsetY: 15);

// 3. Get compact Base64 WebP Data URI placeholder (LQIP)
$dataUri = $page->image->lqip();

// 4. Extract dominant hex color (e.g. "#1e3a5f")
$hex = $page->image->dominantColor();

// 5. Extract dominant color palette (e.g. ["#1e3a5f", "#38bdf8", ...])
$palette = $page->image->palette(6);

// 6. Extract Vibrant / Muted color theme swatches
$theme = $page->image->colorTheme();
// ['vibrant' => '#...', 'muted' => '#...', 'darkVibrant' => '#...', 'darkMuted' => '#...', 'lightVibrant' => '#...', 'lightMuted' => '#...']

// 7. Clear all variations & caches (.lqip.webp, .colors.json, .queue) for a specific Page
$stats = $page->clearImageVariations();

// 8. Clear all variations & caches site-wide (or pass a Page, ID, or path)
$stats = $modules->get('InterventionImage')->clearVariations();
```

### Watermark (`insert`) Option

You can also pass `insert` via options during `size()` or `render()`:

```php
echo $page->image->render('landscape', [
    'insert' => [
        'element' => '/path/to/watermark.png', // Absolute path or Pageimage object
        'position' => 'bottom-right',          // Alignment (e.g. top-left, center, bottom-right)
        'offset_x' => 15,                      // X axis offset
        'offset_y' => 15,                      // Y axis offset
        'opacity' => 50                        // Opacity level (0-100)
    ]
]);
```

### 4. Delayed Rendering Logic

When you request an image size, the module:

1.  Calculates the dimensions.
2.  Returns a URL to the file path.
3.  If the file doesn't exist, it creates a lightweight `.queue` JSON file.
4.  When the browser requests the image URL, ProcessWire's 404 handler intercepts the request, reads the queue file, generates the image on the fly, and serves it.

This prevents the server from hanging while generating dozens of thumbnails during a page save or initial render.

## Advanced: Srcset & Sizes

The module automatically calculates `srcset` based on the configured **Factors** (default: `0.5, 1, 1.5, 2`).

For a 1000px image, it generates variants at:

- 500px (0.5x)
- 1000px (1x)
- 1500px (1.5x)
- 2000px (2x)

This ensures crisp images on High-DPI (Retina) displays.

```php
// Get just the srcset string
$srcset = $page->image->srcset('landscape');
```

## ProcessWire Console & Boost Integration

This module includes first-class support for [`trk/processwire-console`](https://github.com/trk/processwire-console) and [`trk/processwire-boost`](https://github.com/trk/processwire-boost).

### CLI Commands (`vendor/bin/wire`)

When `trk/processwire-console` is installed, the module automatically registers the `image:queue` and `image:clear` commands:

```bash
# Inspect pending delayed (.queue) variations
php vendor/bin/wire image:queue --status

# Process up to 50 pending delayed variations via CLI / cron
php vendor/bin/wire image:queue --limit=50

# Remove pending .queue files without rendering
php vendor/bin/wire image:queue --clear

# Preview or remove generated variations, .lqip.webp, .colors.json, and .queue files
php vendor/bin/wire image:clear --dry-run
php vendor/bin/wire image:clear --page=1024
php vendor/bin/wire image:clear --lqip-only
php vendor/bin/wire image:clear --colors-only
```

### Automated Tests (`tests/`)

Run the module's Unit and Feature test suite via Pest:

```bash
php vendor/bin/wire test
```

### AI Agent Skill (`pw-intervention-image`)

When `trk/processwire-boost` is installed, `AGENTS.md` and `.agents/skills/pw-intervention-image/SKILL.md` are automatically discovered and synced to your AI coding assistant.

