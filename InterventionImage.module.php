<?php

declare(strict_types=1);

namespace ProcessWire;

use Intervention\Image\Alignment;
use Intervention\Image\Colors\Theme;
use Intervention\Image\Colors\Themes\VibrantMuted\Theme as VibrantMutedTheme;
use Intervention\Image\Direction;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ColorInterface;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ModifierInterface;

if (!class_exists(ImageManager::class)) {
    require __DIR__ . '/vendor/autoload.php';
}

/**
 * InterventionImageEngine for ProcessWire
 * 
 * High-performance responsive image engine using Intervention Image v4.4.
 * Supports delayed rendering, auto-detection of drivers, pixel-accurate focal-point + zoom cropping,
 * color extraction (dominantColor, palette, colorTheme), and modern formats like WebP/AVIF.
 * 
 * @property array $options
 * @property string $driver
 * @property bool $lqip
 * @property bool $lazyload
 * @property bool $inlineLazyload
 * @property int $quality
 * @property bool $delayed
 * @property bool $upscaling
 * @property bool $strip
 * @property bool $progressive
 * @property bool $avifAdd
 * @property bool $webpAdd
 * @property string $outputFormat
 * @property string $breakpoints
 * @property string $aspectRatios
 * @property string $factors
 * @property string $columnWidths
 * 
 * @author Iskender TOTOGLU @trk @ukyo
 * @link https://github.com/trk/InterventionImage
 */
class InterventionImage extends WireData implements Module, ConfigurableModule
{
    /** @var ImageManager Intervention image manager instance */
    protected ImageManager $intervention;

    /** @var array Merged image sizer options from ProcessWire config */
    protected array $imageSizeOptions;

    /** @var array Formatted configurations for internal use */
    protected array $formatted = [
        'breakpoint' => [],
        'breakpoints' => [],
        'aspectRatio' => [],
        'aspectRatios' => [],
        'factors' => [],
        'columnWidths' => []
    ];

    /**
     * Default configurations
     * 
     * @var array
     */
    protected array $defaults = [
        'options' => ['delayed', 'lqip', 'lazyload', 'inlineLazyload', 'strip', 'progressive'],
        'driver' => 'auto',
        'quality' => 80,
        'outputFormat' => 'webpOnly',
        'breakpoints' => "640=s|Small\n960=m|Medium\n1200=+l|Large\n1600=xl|Extra Large",
        'aspectRatios' => "1:1=square|Square\n16:9=+landscape|Landscape\n3:4=portrait|Portrait",
        'factors' => "0.5,1,1.5,2",
        'columnWidths' => "1-1,1-2,1-3,2-3,1-4,3-4,1-5,1-6"
    ];

    /**
     * Module Info
     * 
     * @return array
     */
    public static function getModuleInfo(): array
    {
        return [
            'title' => __('Intervention Image Engine'),
            'version' => 110,
            'summary' => __('Replaces PW sizing with Intervention Image v4.4 + Delayed Rendering.'),
            'author' => 'Iskender TOTOGLU @trk @ukyo',
            'href' => 'https://github.com/trk/InterventionImage',
            'autoload' => true,
            'singular' => true,
        ];
    }

    /**
     * Initialize the module
     */
    public function __construct()
    {
        parent::__construct();
        foreach ($this->defaults as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Initialize the module and register hooks
     * 
     * @return void
     */
    public function init(): void
    {
        $enabledOptions = is_array($this->options) ? $this->options : [];
        $map = [
            'delayed' => 'delayed',
            'lqip' => 'lqip',
            'lazyload' => 'lazyload',
            'inlineLazyload' => 'inlineLazyload',
            'upscale' => 'upscaling',
            'strip' => 'strip',
            'progressive' => 'progressive',
            'avifAdd' => 'avifAdd',
            'webpAdd' => 'webpAdd',
        ];
        foreach ($map as $opt => $internal) {
            $this->set($internal, in_array($opt, $enabledOptions, true));
        }

        $this->setupIntervention();
        $this->setupImageOptions();
        $this->parseAndSetupConfigs();

        if ($this->inlineLazyload) {
            $this->addHookAfter('Page::render', function (HookEvent $event): void {
                if ($this->wire()->config->admin || !is_string($event->return)) {
                    return;
                }
                $event->return = str_replace('</head>', $this->inlineLazyload() . '</head>', $event->return);
            });
        }

        // Hooks for Pageimage sizing & manipulation methods
        $this->addHookBefore('Pageimage::crop', $this, 'hookCrop', ['priority' => 100]);
        $this->addHookBefore('Pageimage::size', $this, 'hookSize', ['priority' => 100]);
        $this->addHookMethod('Pageimage::contain', $this, 'hookContain');
        $this->addHookMethod('Pageimage::watermark', $this, 'hookWatermark');

        // Rendering & Responsive hooks
        $this->addHookBefore('Pageimage::render', $this, 'hookRender', ['priority' => 100]);
        $this->addHookMethod('Pageimage::attrs', $this, 'hookAttrs');
        $this->addHookMethod('Pageimage::srcset', $this, 'hookSrcset');
        $this->addHookMethod('Pageimage::lqip', $this, 'hookLqip');

        // Color analysis hooks (Intervention v4.4 ColorExtractor)
        $this->addHookMethod('Pageimage::dominantColor', $this, 'hookDominantColor');
        $this->addHookMethod('Pageimage::palette', $this, 'hookPalette');
        $this->addHookMethod('Pageimage::colorTheme', $this, 'hookColorTheme');

        // Cleanup hooks
        $this->addHookAfter('Pagefile::delete', $this, 'hookDeleteVariations');
        $this->addHookAfter('Pageimage::removeVariations', $this, 'hookDeleteVariations');
        $this->addHookMethod('Page::clearImageVariations', $this, 'hookClearPageVariations');

        // Main rendering engine via 404 handler
        $this->addHookBefore('ProcessPageView::pageNotFound', $this, 'handlePageNotFound', ['priority' => 100]);
    }

    protected function inlineLazyload(): string
    {
        $output = <<<HTML
        <style>
            img.lazyload {
                color: transparent;
            }
            img.lazyload.loaded {
                animation: lazyFadeIn 0.5s ease-in-out;
            }
            @keyframes lazyFadeIn {
                from {
                    opacity: 0;
                }
                to {
                    opacity: 1;
                }
            }
        </style>
        HTML;

        $output .= <<<HTML
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const lazyloadImages = document.querySelectorAll("img.lazyload");
                lazyloadImages.forEach((img) => {
                    if (img.complete) {
                        img.classList.add("loaded");
                    } else {
                        img.addEventListener("load", () => img.classList.add("loaded"));
                    }
                });
            });
        </script>
        HTML;
        return $output;
    }

    /**
     * Sets up the Intervention Image manager with fail-safe driver detection
     * Intervention Image v4 signature: ImageManager(string|DriverInterface $driver, ...$options)
     * 
     * @return void
     * @throws \Exception
     */
    protected function setupIntervention(): void
    {
        $selectedDriver = $this->driver ?: 'auto';
        $driverClass = null;

        if ($selectedDriver === 'imagick') {
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                $driverClass = ImagickDriver::class;
            }
        } elseif ($selectedDriver === 'gd') {
            if (extension_loaded('gd')) {
                $driverClass = GdDriver::class;
            }
        }

        if (!$driverClass) {
            if (extension_loaded('gd')) {
                $driverClass = GdDriver::class;
            } elseif (extension_loaded('imagick') && class_exists('Imagick')) {
                $driverClass = ImagickDriver::class;
            }
        }

        if (!$driverClass) {
            throw new \RuntimeException('InterventionImage: No valid image driver found.');
        }

        $coreOptions = $this->wire()->config->imageSizerOptions ?: [];
        $this->intervention = new ImageManager(
            $driverClass,
            autoOrientation: (bool) ($coreOptions['autoRotation'] ?? true),
            decodeAnimation: true,
            backgroundColor: 'ffffff',
            strip: (bool) $this->strip
        );
    }

    /**
     * Returns the configured Intervention Image v4.4 ImageManager instance.
     *
     * @return ImageManager
     */
    public function manager(): ImageManager
    {
        return $this->intervention;
    }

    /**
     * Prepares global image sizer options and format-specific settings
     * 
     * @return void
     */
    protected function setupImageOptions(): void
    {
        $config = $this->wire()->config;
        $coreOptions = $config->imageSizerOptions ?: [];

        $formatOpts = [
            'webpOnly' => ($this->outputFormat === 'webpOnly'),
            'avifOnly' => ($this->outputFormat === 'avifOnly'),
            'webpAdd'  => $this->webpAdd,
            'avifAdd'  => $this->avifAdd,
        ];

        $this->imageSizeOptions = array_merge([
            'delayed' => $this->delayed,
            'upscaling' => $this->upscaling,
            'strip' => $this->strip,
            'progressive' => $this->progressive,
            'cropping' => 'center',
            'quality' => $this->quality,
            'sharpening' => 'soft',
        ], $coreOptions, $formatOpts);

        $this->imageSizeOptions['webp'] = $config->webpOptions ?: ['quality' => $this->quality];
        $this->imageSizeOptions['avif'] = $config->avifOptions ?: ['quality' => $this->quality];
    }

    /**
     * Parses multiline strings into structured arrays and registers sizes to PW config
     * 
     * @return void
     */
    protected function parseAndSetupConfigs(): void
    {
        $config = $this->wire()->config;

        $breakpoints = $this->multilineConfigToArray($this->breakpoints ?: $this->defaults['breakpoints']);
        $aspectRatios = $this->multilineConfigToArray($this->aspectRatios ?: $this->defaults['aspectRatios']);
        $factors = array_map('floatval', array_map('trim', explode(',', $this->factors ?: $this->defaults['factors'])));

        $columnWidths = array_map('trim', explode(',', $this->columnWidths ?: $this->defaults['columnWidths']));

        $this->formatted['breakpoint'] = $breakpoints['default'];
        $this->formatted['breakpoints'] = $breakpoints['data'];
        $this->formatted['aspectRatio'] = $aspectRatios['default'];
        $this->formatted['aspectRatios'] = $aspectRatios['data'];
        $this->formatted['factors'] = $factors;
        $this->formatted['columnWidths'] = $columnWidths;

        foreach ($aspectRatios['data'] as $key => $ratio) {
            foreach ($columnWidths as $columnWidth) {
                [$n, $d] = explode('-', $columnWidth);
                $width = (int) ceil($breakpoints['default']['value'] * ($n / $d));

                $dimensions = $this->calculate($width, null, $key);
                $configKey = ($columnWidth === '1-1') ? $key : "$key-$columnWidth";
                $dimensions['label'] = $ratio['label'] . ($columnWidth === '1-1' ? "" : " ($columnWidth)");
                $config->imageSizes($configKey, $dimensions);
            }
        }
    }

    /**
     * Utility: Converts multiline config string to array
     * 
     * @param string $str
     * @return array
     */
    protected function multilineConfigToArray(string $str): array
    {
        $config = ['default' => [], 'data' => []];
        $lines = array_map('trim', explode("\n", str_replace("\r", "", $str)));

        foreach ($lines as $line) {
            if (empty($line)) continue;

            $conf = ['value' => null, 'key' => null, 'label' => null];

            if (str_contains($line, '=')) {
                [$conf['value'], $rest] = array_pad(explode('=', $line, 2), 2, '');
                if (str_contains($rest, '|')) {
                    [$conf['key'], $conf['label']] = array_pad(explode('|', $rest, 2), 2, '');
                } else {
                    $conf['key'] = $rest;
                    $conf['label'] = $rest;
                }
            } else {
                $conf['value'] = $line;
                $conf['key'] = $line;
                $conf['label'] = $line;
            }

            if (str_contains((string)$conf['value'], ':')) {
                $conf['value'] = array_map('intval', explode(':', $conf['value']));
            } elseif (is_numeric($conf['value'])) {
                $conf['value'] = (int) $conf['value'];
            }

            if (str_starts_with($conf['key'], '+')) {
                $conf['key'] = ltrim($conf['key'], '+');
                $config['default'] = $conf;
            }
            $config['data'][$conf['key']] = $conf;
        }

        if (empty($config['default']) && !empty($config['data'])) {
            $config['default'] = reset($config['data']);
        }

        return $config;
    }

    /**
     * Calculates proportional dimensions based on ratio and breakpoint limits
     * 
     * @param int|null $width
     * @param int|null $height
     * @param string|null $ratioKey
     * @param string|null $breakpointKey
     * @return array
     */
    public function calculate(?int $width, ?int $height, ?string $ratioKey = null, ?string $breakpointKey = null): array
    {
        $breakpoint = $this->formatted['breakpoints'][(string)$breakpointKey] ?? $this->formatted['breakpoint'];
        $maxWidth = $breakpoint['value'] ?? 1200;

        $aspectRatio = $this->formatted['aspectRatios'][(string)$ratioKey] ?? $this->formatted['aspectRatio'];

        // Default to 1:1 only if we are strictly calculating a ratio-based dimension
        // otherwise default to flexible
        if (isset($aspectRatio['value']) && is_array($aspectRatio['value'])) {
            $aw = (float) ($aspectRatio['value'][0] ?? 1);
            $ah = (float) ($aspectRatio['value'][1] ?? 1);
        } else {
            $aw = 1;
            $ah = 1;
        }

        $ratio = $ah / ($aw ?: 1.0);

        // If we have a specific ratio key, force that ratio
        if ($ratioKey) {
            if ($width > 0) {
                $w = min($width, $maxWidth);
                $h = (int) ceil($w * $ratio);
            } else {
                // If no width, use max width
                $w = $maxWidth;
                $h = (int) ceil($w * $ratio);
            }
        } else {
            // No ratio key? behave standard
            $w = $width ?: $maxWidth;
            $h = $height ?: (int) ceil($w * $ratio);
        }

        return [
            'width' => $w,
            'height' => $h,
            'maxWidth' => $maxWidth,
            'ratio' => $ratio
        ];
    }

    /**
     * Resolves parameters
     * 
     * @param Pageimage $image
     * @param int|string|null $width
     * @param int|array|null $height
     * @param array|string|int|bool|null $options
     * 
     * @return array{width: int, height: int, options: array<string, mixed>}
     */
    protected function resolveParameters(Pageimage $image, int|string|null $width, int|array|null $height, array|string|int|bool|null $options = []): array
    {
        /** @var Config $config */
        $config = $this->wire()->config;

        if (is_string($options)) {
            $options = ['cropping' => $options];
        } elseif (is_int($options)) {
            $options = ['quality' => $options];
        } elseif (is_bool($options)) {
            $options = ['upscaling' => $options];
        }
        if (!is_array($options)) {
            $options = [];
        }

        if (is_array($height)) {
            $options = array_merge($options, $height);
            $height = 0;
        }

        if (!is_int($height)) {
            $height = 0;
        }

        if (is_string($width) && !empty($config->imageSizes[$width])) {
            $size = $config->imageSizes[$width];
            $width = $size['width'] ?? 0;
            $height = ($height === 0) ? ($size['height'] ?? 0) : $height;
            $options = array_merge($size, $options);
            unset($options['width'], $options['height']);
        } elseif (is_string($width) && preg_match('/^(\d+)\s*[xX*:]\s*(\d+)$/', trim($width), $m)) {
            $width = (int) $m[1];
            if ($height === 0) {
                $height = (int) $m[2];
            }
        } elseif (is_string($width) && ctype_digit(trim($width))) {
            $width = (int) trim($width);
        }

        if (!is_int($width)) {
            $width = 0;
        }

        if ($width > 0 && $height === 0) {
            $iw = max(1, (int) $image->width);
            $ih = max(1, (int) $image->height);
            $height = (int) round($width * ($ih / $iw));
        } elseif ($height > 0 && $width === 0) {
            $iw = max(1, (int) $image->width);
            $ih = max(1, (int) $image->height);
            $width = (int) round($height / ($ih / $iw));
        }

        if (!empty($options['insert'])) {
            if ($options['insert'] instanceof Pageimage) {
                $options['insert'] = [
                    'element' => $options['insert']->filename,
                ];
            } elseif (is_string($options['insert']) && is_file($options['insert'])) {
                $options['insert'] = [
                    'element' => $options['insert'],
                ];
            }

            if (!is_array($options['insert'])) {
                $options['insert'] = [];
            }

            $options['insert'] = array_merge([
                'element' => null,
                'position' => Alignment::TOP_LEFT->value,
                'offset_x' => 0,
                'offset_y' => 0,
                'opacity' => 100,
            ], $options['insert']);

            if (!is_string($options['insert']['element']) || !file_exists($options['insert']['element'])) {
                $options['insert']['element'] = null;
            }

            $options['insert']['position'] = Alignment::tryCreate((string) $options['insert']['position'])?->value ?? Alignment::TOP_LEFT->value;

            if (!is_int($options['insert']['offset_x'])) {
                $options['insert']['offset_x'] = 0;
            }
            if (!is_int($options['insert']['offset_y'])) {
                $options['insert']['offset_y'] = 0;
            }
            if (!is_int($options['insert']['opacity'])) {
                $options['insert']['opacity'] = 100;
            }
        }

        $options = array_merge($this->imageSizeOptions, $options);

        if (!empty($options['modifiers']) && is_array($options['modifiers'])) {
            foreach ($options['modifiers'] as $modifier) {
                if (!is_string($modifier)) {
                    $options['delayed'] = false;
                    break;
                }
            }
        }

        if ($image->hasFocus && (empty($options['focus']) || !is_array($options['focus']))) {
            $focus = $image->focus();
            $options['focus'] = [
                'top' => (float) ($focus['top'] ?? 50.0),
                'left' => (float) ($focus['left'] ?? 50.0),
                'zoom' => (int) ($focus['zoom'] ?? 0),
            ];
        }

        return [
            'width' => $width,
            'height' => $height,
            'options' => $options,
        ];
    }

    /**
     * Checks whether a Pageimage represents an SVG vector file.
     */
    protected function isSvg(Pageimage $image): bool
    {
        if (strcasecmp($image->ext, 'svg') === 0) {
            return true;
        }

        $imageMime = function_exists('mime_content_type') && is_string($image->filename) && file_exists($image->filename)
            ? @mime_content_type($image->filename)
            : '';

        return $imageMime === 'image/svg+xml';
    }

    /**
     * Maps ProcessWire cropping strings to Intervention v4.4 Alignment enum cases.
     * 
     * @param string|bool|Alignment $crop
     * @return Alignment
     */
    protected function mapCropPosition(string|bool|Alignment $crop): Alignment
    {
        if ($crop instanceof Alignment) {
            return $crop;
        }

        if (is_bool($crop) || $crop === 'true' || $crop === 'center') {
            return Alignment::CENTER;
        }

        $compassMap = [
            'north' => Alignment::TOP,
            'n' => Alignment::TOP,
            'northwest' => Alignment::TOP_LEFT,
            'nw' => Alignment::TOP_LEFT,
            'northeast' => Alignment::TOP_RIGHT,
            'ne' => Alignment::TOP_RIGHT,
            'south' => Alignment::BOTTOM,
            's' => Alignment::BOTTOM,
            'southwest' => Alignment::BOTTOM_LEFT,
            'sw' => Alignment::BOTTOM_LEFT,
            'southeast' => Alignment::BOTTOM_RIGHT,
            'se' => Alignment::BOTTOM_RIGHT,
            'west' => Alignment::LEFT,
            'w' => Alignment::LEFT,
            'east' => Alignment::RIGHT,
            'e' => Alignment::RIGHT,
        ];

        $normalized = strtolower(trim($crop));
        return $compassMap[$normalized] ?? Alignment::tryCreate($normalized) ?? Alignment::CENTER;
    }

    /**
     * Hook: Intercepts crop()
     * 
     * @param HookEvent $event
     */
    public function hookCrop(HookEvent $event): void
    {
        if ($this->wire()->config->admin) {
            return;
        }

        /** @var Pageimage $image */
        $image = $event->object;

        $x = $event->arguments(0);
        $y = $event->arguments(1);

        $params = $this->resolveParameters($image, $event->arguments(2), $event->arguments(3), $event->arguments(4));

        $params['options']['crop_x'] = $x;
        $params['options']['crop_y'] = $y;

        $event->replace = true;
        $event->return = $image->size($params['width'], $params['height'], $params['options']);
    }

    /**
     * Hook: Intercepts Pageimage::size() to initiate delayed rendering
     * Optimized to respect original aspect ratio for standard resize calls.
     * 
     * @param HookEvent $event
     */
    public function hookSize(HookEvent $event): void
    {
        /**
         * @var Config $config
         * @var Pageimage $image
         */
        $config = $this->wire()->config;
        $image = $event->object;

        if ($this->isSvg($image)) {
            $event->replace = true;
            $event->return = $image;
            return;
        }

        if ($config->admin) {
            return;
        }

        $params = $this->resolveParameters($image, $event->arguments(0), $event->arguments(1), $event->arguments(2));
        $path = $this->getVariationPath($image, $params);
        $width = $params['width'];
        $height = $params['height'];
        $options = $params['options'];

        if (!file_exists($path)) {
            if ($options['delayed'] === true) {
                $source = $image->getOriginal() ?: $image;
                $this->createQueue($source, $path, $params);
            } else {
                $this->create($image, $path, $width, $height, $options);
            }
        }

        $event->replace = true;
        $variation = clone $image;
        $variation->setOriginal($image->getOriginal() ?: $image);
        $variation->setFilename($path);
        $event->return = $variation;
    }

    /**
     * Hook: Generates srcset string based on factors
     * Optimized to prevent duplicate widths and near-original sizes.
     * 
     * @param HookEvent $event
     */
    public function hookSrcset(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;

        if ($this->isSvg($image)) {
            $event->return = '';
            return;
        }

        $parameters = $this->resolveParameters(
            $image,
            $event->arguments(0),
            $event->arguments(1),
            $event->arguments(2)
        );

        $origW = max(1, (int) $image->width);
        $origH = max(1, (int) $image->height);

        $baseW = 0;
        $baseH = 0;

        if (isset($parameters['options']['baseWidth'], $parameters['options']['baseHeight'])) {
            $baseW = (int) $parameters['options']['baseWidth'];
            $baseH = (int) $parameters['options']['baseHeight'];
        } elseif ($parameters['width'] > 0) {
            $baseW = $parameters['width'];
            $baseH = $parameters['height'];
        } else {
            $baseW = min($origW, (int) ($this->formatted['breakpoint']['value'] ?? 1200));
            $baseH = (int) round($baseW * ($origH / $origW));
        }

        $variations = [];
        $factors = $this->formatted['factors'] ?? [0.5, 1, 1.5, 2];

        $threshold = 150;
        $targetRatio = ($baseH > 0) ? ($baseW / $baseH) : ($origW / $origH);

        foreach ($factors as $factor) {
            $f = (float) $factor;
            $w = (int) ($baseW * $f);

            if ($w >= ($origW - $threshold)) {
                $w = $origW;
            }

            if ($w === $origW) {
                $origRatio = $origW / $origH;
                if (abs($targetRatio - $origRatio) < 0.01) {
                    $h = $origH;
                } else {
                    $h = (int) round($w / $targetRatio);
                }
            } else {
                $h = (int) round($w / $targetRatio);
            }
            $variations[$w] = $h;
        }

        ksort($variations);
        $srcset = [];

        foreach ($variations as $w => $h) {
            $srcset[] = $image->size($w, $h, $parameters['options'])->url . " {$w}w";
        }

        $event->return = implode(', ', array_unique($srcset));
    }

    /**
     * Hook: Returns all image attributes and sources as an array.
     * Useful for template engines or custom HTML construction.
     * 
     * @param HookEvent $event
     */
    public function hookAttrs(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;

        $params = $this->resolveParameters(
            $image,
            $event->arguments(0),
            $event->arguments(1),
            $event->arguments(2)
        );

        $width = $params['width'];
        $height = $params['height'];
        $options = $params['options'];

        $origW = max(1, (int) $image->width);
        $origH = max(1, (int) $image->height);

        if ($width === 0) {
            $width = min($origW, (int) ($this->formatted['breakpoint']['value'] ?? 1200));
            $height = (int) round($width * ($origH / $origW));
        }

        $isSvg = $this->isSvg($image);
        $source = $isSvg ? $image : $image->size($width, $height, $options);

        $srcsetOptions = array_merge($options, ['baseWidth' => $width, 'baseHeight' => $height]);

        $sources = [];
        if (!$isSvg) {
            // AVIF Source
            if ($this->avifAdd && $source->ext !== 'avif') {
                $sources[] = [
                    'type' => 'image/avif',
                    'srcset' => $image->srcset(null, array_merge($srcsetOptions, ['format' => 'avif'])),
                ];
            }

            // WebP Source
            if ($this->webpAdd && $source->ext !== 'webp') {
                $webpSrcset = $image->srcset(null, array_merge($srcsetOptions, ['format' => 'webp']));
                $sources[] = [
                    'type' => 'image/webp',
                    'srcset' => $webpSrcset,
                ];
            }
        }

        $loading = $options['loading'] ?? null;
        if (is_bool($loading)) {
            $lazyload = $loading;
        } elseif (is_string($loading)) {
            $lazyload = strtolower($loading) !== 'eager';
        } else {
            $lazyload = true;
        }
        $first = !empty($options['isFirst']);

        $attrs = [
            'src' => $source->url,
            'width' => $width,
            'height' => $height,
            'alt' => $image->description ?: pathinfo($image->basename, PATHINFO_FILENAME),
            'srcset' => $isSvg ? '' : $image->srcset(null, $srcsetOptions),
            'sources' => $sources,
            'sizes' => $isSvg ? '' : ($options['sizes'] ?? sprintf('(max-width: %dpx) 100vw, %dpx', $width, $width)),
        ];

        // set class, style as array
        foreach (['class', 'style'] as $k) {
            $attrs[$k] = $options[$k] ?? [];
            if (is_string($attrs[$k])) {
                $attrs[$k] = [$attrs[$k]];
            }
        }

        if ($this->lqip && !$isSvg) {
            $base64 = $this->generateLqip($image);
            if ($base64) {
                $attrs['class'][] = 'lazyload';
                $attrs['style'][] = 'background-image: url("' . $base64 . '"); background-size: cover; background-position: center;';
            }
        }

        $attrs['loading'] = $lazyload ? 'lazy' : 'eager';
        $attrs['decoding'] = $lazyload ? 'async' : 'sync';

        if ($first) {
            $attrs['fetchpriority'] = 'high';
        }

        $event->return = $attrs;
    }

    /**
     * Hook: Standardizes the HTML <img> output with picture tag support
     * Optimized to respect original aspect ratio if no size key is provided.
     * 
     * @param HookEvent $event
     */
    public function hookRender(HookEvent $event): void
    {
        $event->replace = true;

        /** @var Pageimage $image */
        $image = $event->object;

        $attrs = $image->attrs(
            $event->arguments(0),
            $event->arguments(1),
            $event->arguments(2)
        );

        $sources = $attrs['sources'] ?? [];
        unset($attrs['sources']);

        if (!empty($sources)) {
            $sourceHtml = '';
            $sizes = $attrs['sizes'] ?? '';

            foreach ($sources as $source) {
                $sourceAttrs = [
                    'srcset' => $source['srcset'],
                    'sizes' => $sizes,
                    'type' => $source['type'],
                ];
                $sourceHtml .= '<source ' . $this->attrs($sourceAttrs) . '>';
            }

            unset($attrs['srcset'], $attrs['sizes']);

            $imgHtml = '<img ' . $this->attrs($attrs) . '>';
            $event->return = "<picture>{$sourceHtml}{$imgHtml}</picture>";
        } else {
            $event->return = '<img ' . $this->attrs($attrs) . '>';
        }
    }

    /**
     * 404 Handler: Generates the image using Intervention Image v4.4 when requested
     * 
     * @param HookEvent $event
     */
    public function handlePageNotFound(HookEvent $event): void
    {
        $url = (string) ($event->arguments(1) ?: ($_SERVER['REQUEST_URI'] ?? ''));
        if ($url === '') {
            return;
        }

        /**
         * @var Config $config
         * @var WireFileTools $files
         */
        $config = $event->wire()->config;
        $files = $event->wire()->files;

        if (!str_contains($url, $config->urls->files)) {
            return;
        }

        $root = rtrim($config->paths->root, '/\\');
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (!is_string($urlPath) || $urlPath === '') {
            return;
        }
        $destination = $root . str_replace('/', DIRECTORY_SEPARATOR, $urlPath);
        $queueFile = $destination . '.queue';

        if (!$files->exists($queueFile)) {
            return;
        }

        try {
            $encoded = $this->processQueueFile($queueFile);
            if ($encoded === null && !file_exists($destination)) {
                return;
            }

            $event->replace = true;
            $event->cancelHooks = true;

            if ($encoded instanceof EncodedImageInterface) {
                $mime = $encoded->mediaType();
                $body = (string) $encoded;
            } else {
                $body = (string) file_get_contents($destination);
                $mime = mime_content_type($destination) ?: 'application/octet-stream';
            }
            $size = strlen($body);

            if (!headers_sent()) {
                header("Content-Type: $mime");
                header("Content-Length: $size");
                header('Cache-Control: public, max-age=31536000, immutable');
            }

            echo $body;
            if (function_exists('session_write_close')) {
                session_write_close();
            }
            exit;
        } catch (\Throwable $e) {
            $this->wire()->log->error('InterventionImage Error: ' . $e->getMessage());
        }
    }

    /**
     * Processes a single delayed `.queue` sidecar file and generates the image variation.
     *
     * @param string $queueFile Absolute path to the `.queue` file
     * @return EncodedImageInterface|null
     */
    public function processQueueFile(string $queueFile): ?EncodedImageInterface
    {
        /**
         * @var Config $config
         * @var WireFileTools $files
         */
        $config = $this->wire()->config;
        $files = $this->wire()->files;

        if (!$files->exists($queueFile)) {
            return null;
        }

        $data = wireDecodeJSON((string) $files->fileGetContents($queueFile), true);
        if (!is_array($data) || empty($data['source'])) {
            $files->unlink($queueFile);
            return null;
        }

        $rawSource = (string) $data['source'];
        $source = $config->paths->site . ltrim($rawSource, '/');
        if (!$files->exists($source) && str_contains($rawSource, 'assets/files/')) {
            $rel = substr($rawSource, (int) strpos($rawSource, 'assets/files/'));
            $source = $config->paths->site . $rel;
        }
        if (!$files->exists($source)) {
            $files->unlink($queueFile);
            return null;
        }

        $destination = str_ends_with($queueFile, '.queue')
            ? substr($queueFile, 0, -6)
            : $queueFile;

        $encoded = $this->create(
            $source,
            $destination,
            (int) ($data['width'] ?? 0),
            (int) ($data['height'] ?? 0),
            (array) ($data['options'] ?? [])
        );
        $files->unlink($queueFile);

        return $encoded instanceof EncodedImageInterface ? $encoded : null;
    }

    /**
     * Hook: Deletes variation and sidecar files when an original image is deleted or removeVariations() is called.
     * 
     * @param HookEvent $e
     */
    public function hookDeleteVariations(HookEvent $e): void
    {
        if (!$e->object instanceof Pageimage) {
            return;
        }
        $pi = pathinfo($e->object->filename);
        $prefix = $pi['filename'] . '.';
        $dir = $pi['dirname'];

        if (!is_dir($dir)) {
            return;
        }

        try {
            $it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME);
            foreach ($it as $path) {
                $base = basename($path);
                if (str_starts_with($base, $prefix) && $path !== $e->object->filename) {
                    $this->wire()->files->unlink($path);
                }
            }
        } catch (\Throwable $ex) {
            if ($this->wire()->config->debug) {
                $this->wire()->log->warning('InterventionImage variation delete warning: ' . $ex->getMessage());
            }
        }
    }

    /**
     * Hook: Page::clearImageVariations(array $options = [])
     * Clears all image variations, LQIP placeholders, color caches, and .queue files for a specific Page.
     *
     * @param HookEvent $event
     */
    public function hookClearPageVariations(HookEvent $event): void
    {
        /** @var Page $page */
        $page = $event->object;
        $options = is_array($event->arguments(0)) ? $event->arguments(0) : [];
        $event->return = $this->clearVariations($page, $options);
    }

    /**
     * Clears generated image variations, LQIP files (.lqip.webp), color metadata (.colors.json),
     * and delayed queue files (.queue) either globally ($page = null) or for a specific Page.
     *
     * @param Page|int|string|null $page Page object, Page ID, Page path, or null for all pages
     * @param array{variations?: bool, lqip?: bool, colors?: bool, queue?: bool, dryRun?: bool} $options
     * @return array{variations: int, lqip: int, colors: int, queue: int, total: int, bytes: int}
     */
    public function clearVariations(Page|int|string|null $page = null, array $options = []): array
    {
        $config = $this->wire()->config;
        $files = $this->wire()->files;
        $pages = $this->wire()->pages;

        $counts = [
            'variations' => 0,
            'lqip' => 0,
            'colors' => 0,
            'queue' => 0,
            'total' => 0,
            'bytes' => 0,
        ];

        $baseDir = rtrim($config->paths->files, '/\\');

        if ($page !== null && $page !== '') {
            if (!$page instanceof Page) {
                $page = is_numeric($page)
                    ? $pages->get((int) $page)
                    : $pages->get((string) $page);
            }

            if (!$page instanceof Page || !$page->id) {
                throw new \InvalidArgumentException('InterventionImage: Target page not found for clearVariations().');
            }

            $baseDir = rtrim($page->filesManager()->path(), '/\\');
        }

        if (!is_dir($baseDir)) {
            return $counts;
        }

        $hasSpecificFilter = array_key_exists('variations', $options)
            || array_key_exists('lqip', $options)
            || array_key_exists('colors', $options)
            || array_key_exists('queue', $options);

        $clearVariations = $hasSpecificFilter ? !empty($options['variations']) : true;
        $clearLqip = $hasSpecificFilter ? !empty($options['lqip']) : true;
        $clearColors = $hasSpecificFilter ? !empty($options['colors']) : true;
        $clearQueue = $hasSpecificFilter ? !empty($options['queue']) : true;
        $dryRun = !empty($options['dryRun']);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $filename = $fileInfo->getFilename();
            $pathname = $fileInfo->getPathname();
            $category = null;

            if (str_ends_with($filename, '.lqip.webp')) {
                if ($clearLqip) {
                    $category = 'lqip';
                }
            } elseif (str_ends_with($filename, '.colors.json')) {
                if ($clearColors) {
                    $category = 'colors';
                }
            } elseif (str_ends_with($filename, '.queue')) {
                if ($clearQueue) {
                    $category = 'queue';
                }
            } elseif ($clearVariations && preg_match('/\.\d+x\d+(?:-[a-z0-9_.-]+)?\.(?:jpg|jpeg|png|gif|webp|avif)$/i', $filename) === 1) {
                $category = 'variations';
            }

            if ($category === null) {
                continue;
            }

            $size = (int) $fileInfo->getSize();
            if ($dryRun || $files->unlink($pathname)) {
                $counts[$category]++;
                $counts['total']++;
                $counts['bytes'] += $size;
            }
        }

        return $counts;
    }

    /**
     * Hook: Pageimage::contain($width, $height = null, $options = [])
     * Resizes an image to fit within the given dimensions without cropping,
     * optionally padding the remaining canvas with a background color.
     */
    public function hookContain(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;

        $width = $event->arguments(0);
        $height = $event->arguments(1);
        $options = $event->arguments(2) ?? [];

        if (is_string($height) && !ctype_digit(trim($height))) {
            $bg = $height;
            $height = 0;
            $options = is_array($options) ? array_merge(['background' => $bg], $options) : ['background' => $bg];
        } elseif (is_string($options)) {
            $options = ['background' => $options];
        } elseif (!is_array($options)) {
            $options = [];
        }

        $options['cropping'] = 'contain';
        $params = $this->resolveParameters($image, $width, $height, $options);
        $event->return = $image->size($params['width'], $params['height'], $params['options']);
    }

    /**
     * Hook: Pageimage::watermark($watermark, $position = Alignment::BOTTOM_RIGHT, $opacity = 50, $offsetX = 15, $offsetY = 15, $width = null, $height = null, $options = [])
     * Applies a watermark image onto the Pageimage variation.
     */
    public function hookWatermark(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;

        $watermark = $event->arguments(0);
        $position = $event->arguments(1) ?? Alignment::BOTTOM_RIGHT;
        $opacity = (int) ($event->arguments(2) ?? 50);
        $offsetX = (int) ($event->arguments(3) ?? 15);
        $offsetY = (int) ($event->arguments(4) ?? 15);
        $width = $event->arguments(5) ?: $image->width;
        $height = $event->arguments(6) ?: $image->height;
        $options = is_array($event->arguments(7)) ? $event->arguments(7) : [];

        $options['insert'] = [
            'element' => ($watermark instanceof Pageimage) ? $watermark->filename : $watermark,
            'position' => ($position instanceof Alignment) ? $position->value : (string) $position,
            'opacity' => $opacity,
            'offset_x' => $offsetX,
            'offset_y' => $offsetY,
        ];

        $event->return = $image->size($width, $height, $options);
    }

    /**
     * Hook: Pageimage::lqip()
     * Returns a compact Base64 Data URI Low Quality Image Placeholder.
     */
    public function hookLqip(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;
        $event->return = $this->generateLqip($image);
    }

    /**
     * Hook: Pageimage::dominantColor(bool $prefix = true)
     * Extracts the single most visually dominant hex color of the image using Intervention v4.4 ColorExtractor.
     */
    public function hookDominantColor(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;
        $prefix = (bool) ($event->arguments(0) ?? true);

        $meta = $this->extractColorMetadata($image);
        $hex = $meta['dominant'] ?? '';
        if ($hex === '') {
            $event->return = '';
            return;
        }

        $event->return = $prefix ? ('#' . ltrim($hex, '#')) : ltrim($hex, '#');
    }

    /**
     * Hook: Pageimage::palette(int $limit = 6, bool $prefix = true)
     * Extracts the visually dominant color palette using Intervention v4.4 ColorExtractor.
     * 
     * @return void
     */
    public function hookPalette(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;
        $limit = max(1, min(32, (int) ($event->arguments(0) ?? 6)));
        $prefix = (bool) ($event->arguments(1) ?? true);

        $meta = $this->extractColorMetadata($image, $limit);
        $palette = array_slice($meta['palette'] ?? [], 0, $limit);

        $event->return = array_map(
            static fn(string $hex): string => $prefix ? ('#' . ltrim($hex, '#')) : ltrim($hex, '#'),
            $palette
        );
    }

    /**
     * Hook: Pageimage::colorTheme(bool $prefix = true)
     * Extracts the Vibrant/Muted color theme swatches using Intervention v4.4 Theme::VIBRANT_MUTED.
     */
    public function hookColorTheme(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;
        $prefix = (bool) ($event->arguments(0) ?? true);

        $meta = $this->extractColorMetadata($image);
        $theme = $meta['theme'] ?? [];

        $formatted = [];
        foreach ($theme as $swatch => $hex) {
            $formatted[$swatch] = is_string($hex) && $hex !== ''
                ? ($prefix ? ('#' . ltrim($hex, '#')) : ltrim($hex, '#'))
                : null;
        }

        $event->return = $formatted;
    }

    /**
     * Extracts and caches color metadata (dominant color, palette, and Vibrant/Muted theme)
     * for a Pageimage using Intervention Image v4.4 ColorExtractor.
     * 
     * @param Pageimage $image
     * @param int $paletteLimit
     * @return array{dominant: string, palette: list<string>, theme: array<string, ?string>}
     */
    protected function extractColorMetadata(Pageimage $image, int $paletteLimit = 8): array
    {
        $source = $image->getOriginal() ?: $image;
        if ($this->isSvg($source) || !file_exists($source->filename) || filesize($source->filename) === 0) {
            return ['dominant' => '', 'palette' => [], 'theme' => []];
        }

        $cachePath = $source->pagefiles->path . pathinfo($source->basename, PATHINFO_FILENAME) . '.colors.json';
        if (file_exists($cachePath)) {
            $cached = wireDecodeJSON((string) file_get_contents($cachePath), true);
            if (is_array($cached) && isset($cached['dominant'], $cached['palette'], $cached['theme']) && count($cached['palette']) >= $paletteLimit) {
                return $cached;
            }
        }

        try {
            $sample = $this->intervention->decodePath($source->filename)->scaleDown(width: 96, height: 96);
            $extractor = $sample->colors();

            $dominantPalette = $extractor->dominant(max(8, $paletteLimit));
            $paletteHex = array_values(array_map(
                static fn(ColorInterface $color): string => $color->toHex(false),
                $dominantPalette->toArray()
            ));

            /** @var VibrantMutedTheme $vibrantTheme */
            $vibrantTheme = $extractor->theme(Theme::VIBRANT_MUTED);
            $themeMap = [
                'vibrant' => $vibrantTheme->vibrant?->toHex(false),
                'muted' => $vibrantTheme->muted?->toHex(false),
                'darkVibrant' => $vibrantTheme->darkVibrant?->toHex(false),
                'darkMuted' => $vibrantTheme->darkMuted?->toHex(false),
                'lightVibrant' => $vibrantTheme->lightVibrant?->toHex(false),
                'lightMuted' => $vibrantTheme->lightMuted?->toHex(false),
            ];

            $data = [
                'dominant' => $paletteHex[0] ?? '',
                'palette' => $paletteHex,
                'theme' => $themeMap,
            ];

            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $this->wire()->files->filePutContents($cachePath, $json, LOCK_EX);
            }

            return $data;
        } catch (\Throwable $e) {
            if ($this->wire()->config->debug) {
                $this->wire()->log->error('InterventionImage ColorExtractor Error: ' . $e->getMessage());
            }
            return ['dominant' => '', 'palette' => [], 'theme' => []];
        }
    }

    /**
     * Generates a compact Low Quality Image Placeholder (Base64 Data URI)
     * using Intervention Image v4.4 EncodedImageInterface::toDataUri().
     * 
     * @param Pageimage $image
     * @return string
     */
    protected function generateLqip(Pageimage $image): string
    {
        $source = $image->getOriginal() ?: $image;
        if ($this->isSvg($source)) {
            return '';
        }

        $lqipPath = $source->pagefiles->path . pathinfo($source->basename, PATHINFO_FILENAME) . '.lqip.webp';

        if (!file_exists($lqipPath)) {
            try {
                if (!file_exists($source->filename) || filesize($source->filename) === 0) {
                    return '';
                }
                $encoded = $this->intervention->decodePath($source->filename)
                    ->scaleDown(width: 24)
                    ->blur(2)
                    ->encodeUsingFormat(Format::WEBP, quality: 20, strip: true);

                $encoded->save($lqipPath);
                return (string) $encoded->toDataUri();
            } catch (\Throwable $e) {
                if ($this->wire()->config->debug) {
                    $this->wire()->log->error('InterventionImage LQIP Error: ' . $e->getMessage());
                }
                return '';
            }
        }

        if (file_exists($lqipPath)) {
            $data = file_get_contents($lqipPath);
            if ($data !== false) {
                return 'data:image/webp;base64,' . base64_encode($data);
            }
        }

        return '';
    }

    /**
     * Applies pixel-accurate ProcessWire focal point and zoom cropping.
     *
     * @param ImageInterface $image
     * @param int $width
     * @param int $height
     * @param array<int|string, mixed> $focus
     * @param bool $upscale
     */
    protected function applyFocusCrop(ImageInterface $image, int $width, int $height, array $focus, bool $upscale): void
    {
        $topPercent = max(0.0, min(100.0, (float) ($focus['top'] ?? $focus[0] ?? 50.0)));
        $leftPercent = max(0.0, min(100.0, (float) ($focus['left'] ?? $focus[1] ?? 50.0)));
        $zoomPercent = max(0, min(90, (int) ($focus['zoom'] ?? $focus[2] ?? 0)));

        $origW = max(1, $image->width());
        $origH = max(1, $image->height());

        $cropW = $width;
        $cropH = $height;

        if (!$upscale && ($cropW > $origW || $cropH > $origH)) {
            $targetRatio = $cropW / $cropH;
            $origRatio = $origW / $origH;
            if ($origRatio >= $targetRatio) {
                $cropH = $origH;
                $cropW = max(1, (int) round($cropH * $targetRatio));
            } else {
                $cropW = $origW;
                $cropH = max(1, (int) round($cropW / $targetRatio));
            }
        }

        $baseScale = max($cropW / $origW, $cropH / $origH);
        $zoomFactor = $zoomPercent > 0 ? (1.0 / (1.0 - ($zoomPercent / 100.0))) : 1.0;
        $scale = $baseScale * $zoomFactor;

        $scaledW = max($cropW, (int) ceil($origW * $scale));
        $scaledH = max($cropH, (int) ceil($origH * $scale));

        $image->resize($scaledW, $scaledH);

        $focusX = ($leftPercent / 100.0) * $scaledW;
        $focusY = ($topPercent / 100.0) * $scaledH;

        $x = max(0, min($scaledW - $cropW, (int) round($focusX - ($cropW / 2.0))));
        $y = max(0, min($scaledH - $cropH, (int) round($focusY - ($cropH / 2.0))));

        $image->crop($cropW, $cropH, x: $x, y: $y);
    }

    /**
     * Centralized image creation method.
     * Handles decoding, transforming, encoding, and saving the result via Intervention Image v4.4.
     * 
     * @param Pageimage|string $source Pageimage object or absolute path string
     * @param string $destination Absolute destination path
     * @param int $width Target width
     * @param int $height Target height
     * @param array<string, mixed> $options Options array
     * 
     * @return Pageimage|EncodedImageInterface Returns Pageimage for hooks, EncodedImageInterface for direct output
     */
    protected function create(Pageimage|string $source, string $destination, int $width = 0, int $height = 0, array $options = []): Pageimage|EncodedImageInterface
    {
        $filename = ($source instanceof Pageimage) ? $source->filename : $source;

        if (!file_exists($filename)) {
            throw new \RuntimeException('InterventionImage: Source file not found: ' . $filename);
        }

        $image = $this->intervention->decodePath($filename);

        // 1. Pre-Resize Transformations (Trim / Rotate / Flip)
        if (isset($options['trim']) && $options['trim'] !== false) {
            $tolerance = is_numeric($options['trim']) ? max(0, min(100, (int) $options['trim'])) : 0;
            $image->trim($tolerance);
        }

        if (!empty($options['rotate'])) {
            $image->rotate((float) $options['rotate'], $options['background'] ?? null);
        }
        if (!empty($options['flip'])) {
            $direction = match (true) {
                $options['flip'] instanceof Direction => $options['flip'],
                is_string($options['flip']) && str_starts_with(strtolower($options['flip']), 'v') => Direction::VERTICAL,
                default => Direction::HORIZONTAL,
            };
            $image->flip($direction);
        }

        // 2. Resize / Crop / Contain Logic
        $cropping = $options['cropping'] ?? true;
        $upscale = !empty($options['upscaling']) || (bool) $this->upscaling;
        $background = isset($options['background']) && is_string($options['background']) && $options['background'] !== ''
            ? $options['background']
            : null;

        $width = max(1, $width);
        $height = max(1, $height);

        // A. Explicit Coordinates (from hook arguments)
        if (isset($options['crop_x'], $options['crop_y'])) {
            $image->crop($width, $height, x: (int) $options['crop_x'], y: (int) $options['crop_y'], background: $background);
        }
        // B. String Coordinates (e.g. x100y200)
        elseif (is_string($cropping) && preg_match('/^x(\d+)y(\d+)$/i', $cropping, $m)) {
            $image->crop($width, $height, x: (int) $m[1], y: (int) $m[2], background: $background);
        }
        // C. Array Coordinates (e.g. [100, 200] or ['50%', '50%'])
        elseif (is_array($cropping) && count($cropping) === 2) {
            $cx = str_contains((string) $cropping[0], '%')
                ? (int) ($image->width() * ((float) $cropping[0] / 100))
                : (int) $cropping[0];

            $cy = str_contains((string) $cropping[1], '%')
                ? (int) ($image->height() * ((float) $cropping[1] / 100))
                : (int) $cropping[1];

            $image->crop($width, $height, x: $cx, y: $cy, background: $background);
        }
        // D. Contain / Pad Mode (fit inside box without cropping)
        elseif ($cropping === 'contain' || !empty($options['contain'])) {
            $align = $this->mapCropPosition(is_string($cropping) && $cropping !== 'contain' ? $cropping : Alignment::CENTER);
            $upscale
                ? $image->contain($width, $height, background: $background ?? 'ffffff', alignment: $align)
                : $image->containDown($width, $height, background: $background ?? 'ffffff', alignment: $align);
        }
        // E. Pixel-Accurate Focal Point + Zoom Cropping
        elseif (($cropping === true || $cropping === 'center') && !empty($options['focus']) && is_array($options['focus'])) {
            $this->applyFocusCrop($image, $width, $height, $options['focus'], $upscale);
        }
        // F. Standard Alignment Cover or Proportional Scale
        else {
            if ($width && $height && $cropping !== false) {
                $align = $this->mapCropPosition($cropping);
                $upscale
                    ? $image->cover($width, $height, $align)
                    : $image->coverDown($width, $height, $align);
            } else {
                $upscale
                    ? $image->scale($width ?: null, $height ?: null)
                    : $image->scaleDown($width ?: null, $height ?: null);
            }
        }

        // Fill transparent areas if background color is explicitly specified
        if ($background !== null && !empty($options['fillTransparent'])) {
            $image->fillTransparentAreas($background);
        }

        // 3. Effects — all values clamped per Intervention v4.4 contracts
        if (($options['sharpening'] ?? 'soft') !== 'none') {
            $amount = ['medium' => 20, 'strong' => 40, 'soft' => 10][(string) $options['sharpening']] ?? 10;
            $image->sharpen(max(0, min(100, (int) $amount)));
        }

        if (isset($options['brightness']) && is_numeric($options['brightness'])) {
            $image->brightness(max(-100, min(100, (int) $options['brightness'])));
        }

        if (isset($options['contrast']) && is_numeric($options['contrast'])) {
            $image->contrast(max(-100, min(100, (int) $options['contrast'])));
        }

        if (isset($options['gamma']) && is_numeric($options['gamma'])) {
            $image->gamma(max(0.1, min(10.0, (float) $options['gamma'])));
        }

        if (!empty($options['colorize'])) {
            $colorize = $this->normalizeColorize($options['colorize']);
            if ($colorize[0] !== 0 || $colorize[1] !== 0 || $colorize[2] !== 0) {
                $image->colorize(...$colorize);
            }
        }

        if (!empty($options['grayscale'])) {
            $image->grayscale();
        }
        if (!empty($options['blur'])) {
            $image->blur(max(0, min(100, (int) $options['blur'])));
        }
        if (!empty($options['sharpen'])) {
            $image->sharpen(max(0, min(100, (int) $options['sharpen'])));
        }
        if (!empty($options['invert'])) {
            $image->invert();
        }
        if (!empty($options['pixelate'])) {
            $image->pixelate(max(1, (int) $options['pixelate']));
        }
        if (!empty($options['reduceColors'])) {
            $image->reduceColors(max(1, min(256, (int) $options['reduceColors'])), $background);
        }

        // 4. Watermark / Insert — Intervention v4.4 insert(mixed $image, int $x = 0, int $y = 0, string|Alignment $alignment = Alignment::TOP_LEFT, float $transparency = 1)
        if (!empty($options['insert']) && is_array($options['insert']) && !empty($options['insert']['element'])) {
            $opacityInt = max(0, min(100, (int) ($options['insert']['opacity'] ?? 100)));
            $transparency = $opacityInt / 100;
            $alignment = Alignment::tryCreate((string) ($options['insert']['position'] ?? 'top-left')) ?? Alignment::TOP_LEFT;
            $image->insert(
                $options['insert']['element'],
                (int) ($options['insert']['offset_x'] ?? 0),
                (int) ($options['insert']['offset_y'] ?? 0),
                $alignment,
                $transparency
            );
        }

        // 4b. Custom Intervention Image v4.4 Modifiers / Callables Seam
        if (!empty($options['modifiers']) && is_array($options['modifiers'])) {
            foreach ($options['modifiers'] as $modifier) {
                if ($modifier instanceof ModifierInterface) {
                    $image->modify($modifier);
                } elseif (is_string($modifier) && class_exists($modifier) && is_subclass_of($modifier, ModifierInterface::class)) {
                    $image->modify(new $modifier());
                } elseif (is_callable($modifier)) {
                    $result = $modifier($image);
                    if ($result instanceof ImageInterface) {
                        $image = $result;
                    }
                }
            }
        }

        // 5. Encode & Save via Intervention v4.4 Format enum
        $baseQuality = max(0, min(100, (int) ($options['quality'] ?? $this->quality ?: 80)));
        $ext = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
        $format = Format::tryCreate($ext) ?? Format::JPEG;

        if ($format === Format::JPEG && $background !== null) {
            $image->fillTransparentAreas($background);
        }

        if (in_array($format, [Format::WEBP, Format::AVIF], true)) {
            $native = $image->core()->native();
            if ($native instanceof \GdImage && !imageistruecolor($native)) {
                imagepalettetotruecolor($native);
            }
        }

        $quality = match ($format) {
            Format::WEBP => max(0, min(100, (int) ($options['quality'] ?? $this->imageSizeOptions['webp']['quality'] ?? $baseQuality))),
            Format::AVIF => max(0, min(100, (int) ($options['quality'] ?? $this->imageSizeOptions['avif']['quality'] ?? $baseQuality))),
            default => $baseQuality,
        };

        $strip = isset($options['strip']) ? (bool) $options['strip'] : (bool) $this->strip;
        $progressive = isset($options['progressive']) ? (bool) $options['progressive'] : (bool) $this->progressive;
        $interlaced = isset($options['interlaced']) ? (bool) $options['interlaced'] : $progressive;

        $encoded = $image->encodeUsingFormat(
            $format,
            quality: $quality,
            strip: $strip,
            progressive: $progressive,
            interlaced: $interlaced
        );
        $encoded->save($destination);

        if ($source instanceof Pageimage) {
            $variation = clone $source;
            $variation->setOriginal($source);
            $variation->setFilename($destination);
            return $variation;
        }

        return $encoded;
    }

    /**
     * Helper: Determines file path and format based on configuration
     * 
     * @param Pageimage $image
     * @param array<width: int, height: int, options: array> $params
     * 
     * @return string
     */
    protected function getVariationPath(Pageimage $image, array $params): string
    {
        $source = $image->getOriginal() ?: $image;
        $ext = $source->ext;

        $format = $params['options']['format'] ?? '';
        $avifOnly = !empty($this->imageSizeOptions['avifOnly']);
        $webpOnly = !empty($this->imageSizeOptions['webpOnly']);

        if ($avifOnly || $format === 'avif') {
            $ext = 'avif';
        } elseif ($webpOnly || $format === 'webp') {
            $ext = 'webp';
        } elseif (in_array($format, ['png', 'gif', 'jpg', 'jpeg'], true)) {
            $ext = ($format === 'jpeg') ? 'jpg' : $format;
        }

        $basename = pathinfo($source->basename, PATHINFO_FILENAME);
        return $source->pagefiles->path() . $basename . $this->createSuffix($params) . '.' . $ext;
    }

    /**
     * Creates the JSON queue file used for on-demand processing.
     * 
     * @param Pageimage $source
     * @param string $destPath
     * @param array<width: int, height: int, options: array> $params
     * 
     * @return void
     */
    protected function createQueue(Pageimage $source, string $destPath, array $params): void
    {
        /**
         * @var WireFileTools $files
         */
        $files = $this->wire()->files;
        $src = str_replace($this->wire()->config->paths->site, '', $source->filename);
        $data = [
            'source' => $src,
            'width' => $params['width'],
            'height' => $params['height'],
            'options' => $params['options']
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('InterventionImage: Queue JSON encode failed for ' . basename($destPath));
        }
        $files->filePutContents($destPath . '.queue', $json, LOCK_EX);
    }

    /**
     * Creates filename suffix conforming to ProcessWire standards
     * Standard: .widthxheight[-suffix].ext
     * 
     * @param array<width: int, height: int, options: array> $params
     * 
     * @return string
     */
    protected function createSuffix(array $params): string
    {
        $sanitizer = $this->wire()->sanitizer;

        $width = $params['width'];
        $height = $params['height'];
        $options = $params['options'];

        $parts = [];

        // Custom Suffixes
        if (!empty($options['suffix'])) {
            $list = is_array($options['suffix']) ? $options['suffix'] : explode(' ', $options['suffix']);
            foreach ($list as $s) {
                if ($c = $sanitizer->fieldName($s)) {
                    $parts[] = $c;
                }
            }
        }

        // Standard Params
        if (isset($options['trim']) && $options['trim'] !== false) {
            $parts[] = 'trm' . (is_numeric($options['trim']) ? (int) $options['trim'] : 0);
        }
        if (!empty($options['rotate'])) {
            $parts[] = 'rot' . (int) $options['rotate'];
        }
        if (!empty($options['flip'])) {
            $flipCode = ($options['flip'] instanceof Direction)
                ? ($options['flip'] === Direction::VERTICAL ? 'v' : 'h')
                : substr((string) $options['flip'], 0, 1);
            $parts[] = 'flip' . $flipCode;
        }
        if (!empty($options['hidpi'])) {
            $parts[] = 'hidpi';
        }

        // Crop / Contain / Focus Info
        if (isset($options['crop_x'], $options['crop_y'])) {
            $parts[] = "c{$options['crop_x']}x{$options['crop_y']}";
        } elseif (!empty($options['contain']) || ($options['cropping'] ?? null) === 'contain') {
            $parts[] = 'fit';
        } elseif (!empty($options['focus']) && is_array($options['focus'])) {
            $ft = (int) round((float) ($options['focus']['top'] ?? $options['focus'][0] ?? 50));
            $fl = (int) round((float) ($options['focus']['left'] ?? $options['focus'][1] ?? 50));
            $fz = (int) ($options['focus']['zoom'] ?? $options['focus'][2] ?? 0);
            if ($ft !== 50 || $fl !== 50 || $fz > 0) {
                $parts[] = "f{$ft}x{$fl}" . ($fz > 0 ? "z{$fz}" : '');
            }
        } elseif (!empty($options['cropping']) && is_string($options['cropping']) && !in_array($options['cropping'], ['center', 'true'], true)) {
            $parts[] = $sanitizer->fieldName($options['cropping']);
        }

        if (!empty($options['background']) && is_string($options['background'])) {
            $parts[] = 'bg' . $sanitizer->fieldName(ltrim($options['background'], '#'));
        }

        // Watermark / Insert
        if (!empty($options['insert']) && !empty($options['insert']['element'])) {
            $insert = substr(md5((string) $options['insert']['element']), 0, 8);
            $pos = [
                'top-left' => 'tl',
                'top' => 't',
                'top-right' => 'tr',
                'left' => 'l',
                'center' => 'c',
                'right' => 'r',
                'bottom-left' => 'bl',
                'bottom' => 'b',
                'bottom-right' => 'br',
            ][$options['insert']['position']] ?? 'tl';

            $parts[] = "ins{$insert}_{$pos}_{$options['insert']['offset_x']}x{$options['insert']['offset_y']}_{$options['insert']['opacity']}";
        }

        // Gamma (e.g. -gam10)
        if (!empty($options['gamma'])) {
            $parts[] = 'gam' . (float) $options['gamma'];
        }

        // Brightness (e.g. -bri10)
        if (!empty($options['brightness'])) {
            $parts[] = 'bri' . (float) $options['brightness'];
        }

        // Contrast (e.g. -con10)
        if (!empty($options['contrast'])) {
            $parts[] = 'con' . (float) $options['contrast'];
        }

        // Colorize (e.g. -col10-0-0)
        if (!empty($options['colorize'])) {
            [$cr, $cg, $cb] = $this->normalizeColorize($options['colorize']);
            if ($cr !== 0 || $cg !== 0 || $cb !== 0) {
                $parts[] = "col{$cr}-{$cg}-{$cb}";
            }
        }

        // Grayscale (e.g. -gre)
        if (!empty($options['grayscale'])) {
            $parts[] = 'gre';
        }

        // Blur (e.g. -blu10)
        if (!empty($options['blur'])) {
            $parts[] = 'blu' . (int) $options['blur'];
        }

        // Sharpen (e.g. -sha10)
        if (!empty($options['sharpen'])) {
            $parts[] = 'sha' . (int) $options['sharpen'];
        }

        // Invert (e.g. -inv)
        if (!empty($options['invert'])) {
            $parts[] = 'inv';
        }

        // Pixelate (e.g. -pix10)
        if (!empty($options['pixelate'])) {
            $parts[] = 'pix' . (int) $options['pixelate'];
        }

        // Reduce Colors (e.g. -rc64)
        if (!empty($options['reduceColors'])) {
            $parts[] = 'rc' . (int) $options['reduceColors'];
        }

        // Custom Modifiers (e.g. -mod8f3a1b)
        if (!empty($options['modifiers']) && is_array($options['modifiers'])) {
            $modTokens = [];
            foreach ($options['modifiers'] as $mod) {
                if (is_string($mod)) {
                    $modTokens[] = $mod;
                } elseif ($mod instanceof \Closure) {
                    $ref = new \ReflectionFunction($mod);
                    $modTokens[] = $ref->getFileName() . ':' . $ref->getStartLine() . '-' . $ref->getEndLine();
                } elseif (is_object($mod)) {
                    $modTokens[] = $mod::class;
                }
            }
            if (!empty($modTokens)) {
                $parts[] = 'mod' . substr(md5(implode('|', $modTokens)), 0, 6);
            }
        }

        // Custom Quality override (e.g. -q90)
        $defaultQ = (int) ($this->imageSizeOptions['quality'] ?? $this->quality ?: 80);
        if (isset($options['quality']) && is_numeric($options['quality']) && (int) $options['quality'] !== $defaultQ) {
            $parts[] = 'q' . max(0, min(100, (int) $options['quality']));
        }

        return '.' . $width . 'x' . $height . (!empty($parts) ? '-' . implode('-', $parts) : '');
    }

    /**
     * Normalizes colorize input options into [red, green, blue] integers.
     * 
     * Supports:
     * - String: "10,20,30" or "10,20"
     * - Indexed Array: [10, 20, 30]
     * - Assoc Array: ['red' => 10, 'green' => 20, 'blue' => 30]
     * 
     * @param mixed $input
     * @return array{0: int, 1: int, 2: int}
     */
    protected function normalizeColorize(mixed $input): array
    {
        $red = 0;
        $green = 0;
        $blue = 0;

        if (is_string($input)) {
            $parts = array_map('trim', explode(',', $input));
            $red = $parts[0] ?? 0;
            $green = $parts[1] ?? 0;
            $blue = $parts[2] ?? 0;
        } elseif (is_array($input)) {
            $red = $input['red'] ?? $input['r'] ?? $input[0] ?? 0;
            $green = $input['green'] ?? $input['g'] ?? $input[1] ?? 0;
            $blue = $input['blue'] ?? $input['b'] ?? $input[2] ?? 0;
        }

        return [
            max(-100, min(100, (int) $red)),
            max(-100, min(100, (int) $green)),
            max(-100, min(100, (int) $blue)),
        ];
    }

    /**
     * Helper: Converts an associative array to an HTML attribute string.
     * Skips null, false values.
     * 
     * @param array $attributes
     * 
     * @return string
     */
    protected function attrs(array $attributes): string
    {
        $attrs = [];
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false || (is_string($value) && !strlen($value))) {
                continue;
            }

            if ($value === true) {
                $attrs[] = $key;
                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', array_map(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'), $value));
            } else {
                $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            }

            $attrs[] = $key . '="' . $value . '"';
        }
        return implode(' ', $attrs);
    }

    /**
     * @param InputfieldWrapper $inputfields
     */
    public function getModuleConfigInputfields(InputfieldWrapper $inputfields): void
    {
        $modules = $this->wire()->modules;

        /** @var InputfieldFieldset $fs */
        $fs = $modules->get('InputfieldFieldset');
        $fs->name = 'intervention';
        $fs->label = __('Settings');
        $inputfields->add($fs);

        /** @var InputfieldRadios $f */
        $f = $modules->get('InputfieldRadios');
        $f->name = 'driver';
        $f->label = __('Image Processing Driver');
        $f->addOptions([
            'auto' => __('Auto Detect'),
            'imagick' => __('Force Imagick'),
            'gd' => __('Force GD')
        ]);
        $f->value = $this->driver ?: 'auto';
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldCheckboxes $f */
        $f = $modules->get('InputfieldCheckboxes');
        $f->name = 'options';
        $f->label = __('Options');
        $f->description = __('Select additional options for image processing.');
        $f->addOptions([
            'delayed' => __('Delays image processing until it is requested'),
            'lqip' => __('Enable Low-Quality Image Placeholder (LQIP)'),
            'lazyload' => __('Enable Fade-in Lazy Loading effect'),
            'inlineLazyload' => __('Add Lazy Loading fade in inline style and script'),
            'upscale' => __('Upscale images to fit requested dimensions'),
            'strip' => __('Strip EXIF/Metadata from generated variations'),
            'progressive' => __('Enable progressive JPEG / interlaced PNG encoding'),
            'avifAdd' => __('Add AVIF format to picture tags'),
            'webpAdd' => __('Add WebP format to picture tags')
        ]);
        $f->value = is_array($this->options) ? $this->options : [];
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldInteger $f */
        $f = $modules->get('InputfieldInteger');
        $f->name = 'quality';
        $f->label = __('Default Quality');
        $f->description = __('Default quality for generated images (0-100)');
        $f->set('inputType', 'number');
        $f->set('size', 0);
        $f->set('min', 0);
        $f->set('max', 100);
        $f->set('step', 5);
        $f->value = $this->quality ?: 80;
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldSelect $f */
        $f = $modules->get('InputfieldSelect');
        $f->name = 'outputFormat';
        $f->label = __('Default Output Format');
        $f->description = __('Default output format for generated images.');
        $f->addOptions([
            'original' => __('Original'),
            'webpOnly' => __('WebP Only'),
            'avifOnly' => __('AVIF Only')
        ]);
        $f->value = $this->outputFormat ?: $this->defaults['outputFormat'];
        $f->required = true;
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldTextarea $f */
        $f = $modules->get('InputfieldTextarea');
        $f->name = 'breakpoints';
        $f->label = __('Breakpoints');
        $f->description = __('Add breakpoints for responsive images. One per line.');
        $f->value = $this->breakpoints ?: $this->defaults['breakpoints'];
        $f->notes = __('Breakpoints should be in the format `value=key|Label`. For set default breakpoint `value=+key|Label` format.');
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldTextarea $f */
        $f = $modules->get('InputfieldTextarea');
        $f->name = 'aspectRatios';
        $f->label = __('Aspect Ratios');
        $f->description = __('Add aspect ratios for responsive images. One per line.');
        $f->notes = __('Aspect ratios should be in the format `width:height=key|Label`. For set default aspect ratio `width:height=+key|Label` format.');
        $f->value = $this->aspectRatios ?: $this->defaults['aspectRatios'];
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->name = 'columnWidths';
        $f->label = __('Grid Columns');
        $f->description = __('Comma-separated list of grid columns for responsive images.');
        $f->value = $this->columnWidths ?: $this->defaults['columnWidths'];
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->name = 'factors';
        $f->label = __('Responsive image resizing factors');
        $f->description = __('Comma-separated list of responsive image resizing factors.');
        $f->value = $this->factors ?: $this->defaults['factors'];
        $f->columnWidth = 50;
        $fs->add($f);
    }
}
