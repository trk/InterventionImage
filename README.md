# Intervention Image Engine for ProcessWire

A high-performance responsive image engine for ProcessWire powered by [Intervention Image v3](https://image.intervention.io/v3). This module replaces the default ProcessWire image sizing engine with a modern, feature-rich alternative that supports **delayed rendering**, **next-gen formats (WebP/AVIF)**, and **fluid responsive sizing**.

## Features

- **Delayed Rendering**: Images are generated on-demand when requested by the browser, significantly speeding up initial page loads.
- **Modern Formats**: Native support for WebP and AVIF output.
- **Driver Agnostic**: Automatically selects the best available driver (Imagick or GD).
- **Responsive System**: Define centralized breakpoints, aspect ratios, and column widths.
- **Smart Cropping**: Supports focal point cropping (via standard PW options) and position mapping.
- **Srcset Generation**: Automatically generates `srcset` attributes based on configurable scaling factors.

## Requirements

- ProcessWire 3.x
- PHP 8.1+
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
