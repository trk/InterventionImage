<?php

namespace ProcessWire;

use Intervention\Image\ImageManager;
use Intervention\Image\EncodedImage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

if (!class_exists('Intervention\Image\ImageManager')) require __DIR__ . "/vendor/autoload.php";

/**
 * InterventionImageEngine for ProcessWire
 * 
 * High-performance responsive image engine using Intervention Image v3.
 * Supports delayed rendering, auto-detection of drivers, and modern formats like WebP/AVIF.
 * 
 * @property string $driver
 * @property bool $lqip
 * @property bool $lazyload
 * @property bool $inlineLazyload
 * @property int $quality
 * @property bool $delayed
 * @property bool $upscale
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
    const VERSION = '0.0.3';

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
        'options' => ['delayed', 'lqip', 'lazyload', 'inlineLazyload'], // delayed, lazyload, upscale, avifAdd, webpAdd
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
            'version' => self::VERSION,
            'summary' => __('Replaces PW sizing with Intervention Image + Delayed Rendering using ImageManager logic.'),
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
        foreach ($this->defaults as $key => $value) $this->set($key, $value);
    }

    /**
     * Initialize the module and register hooks
     * 
     * @return void
     */
    public function init(): void
    {
        foreach (['delayed', 'lqip', 'lazyload', 'inlineLazyload', 'upscale', 'avifAdd', 'webpAdd'] as $key) {
            $this->set($key, in_array($key, $this->options));
        }

        $this->setupIntervention();
        $this->setupImageOptions();
        $this->parseAndSetupConfigs();

        if ($this->inlineLazyload) {
            $this->addHookAfter('Page::render', function (HookEvent $event) {
                $event->replace = true;
                $event->return = str_replace('</head>', $this->inlineLazyload() . '</head>', $event->return);
            });
        }

        // Hooks for Pageimage methods
        $this->addHookBefore('Pageimage::crop', $this, 'hookCrop', ['priority' => 100]);
        $this->addHookBefore('Pageimage::size', $this, 'hookSize', ['priority' => 100]);

        // Rendering hooks
        $this->addHookBefore('Pageimage::render', $this, 'hookRender', ['priority' => 100]);
        $this->addHookMethod('Pageimage::attrs', $this, 'hookAttrs');
        $this->addHookMethod('Pageimage::srcset', $this, 'hookSrcset');

        // Cleanup hook
        $this->addHookAfter('Pagefile::delete', $this, 'hookDeleteVariations');

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
     * 
     * @return void
     * @throws \Exception
     */
    protected function setupIntervention(): void
    {
        $selectedDriver = $this->driver ?: 'auto';
        $driver = null;

        if ($selectedDriver === 'imagick') {
            if (extension_loaded('imagick') && class_exists('\Imagick::class')) {
                $driver = new ImagickDriver();
            }
        } elseif ($selectedDriver === 'gd') {
            if (extension_loaded('gd')) {
                $driver = new GdDriver();
            }
        }

        if (!$driver) {
            if (extension_loaded('gd')) {
                $driver = new GdDriver();
            } elseif (extension_loaded('imagick') && class_exists('\Imagick::class')) {
                $driver = new ImagickDriver();
            }
        }

        if (!$driver) {
            throw new \Exception("InterventionImage: No valid image driver found.");
        }

        $this->intervention = new ImageManager($driver);
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
            'upscaling' => $this->upscale,
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
                list($n, $d) = explode('-', $columnWidth);
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
                list($conf['value'], $rest) = explode('=', $line);
                if (str_contains($rest, '|')) {
                    list($conf['key'], $conf['label']) = explode('|', $rest);
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
        $breakpoint = $this->formatted['breakpoints'][$breakpointKey] ?? $this->formatted['breakpoint'];
        $maxWidth = $breakpoint['value'] ?? 1200;

        $aspectRatio = $this->formatted['aspectRatios'][$ratioKey] ?? $this->formatted['aspectRatio'];

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
     * @return array{width: int, height: int, options: array}
     */
    protected function resolveParameters(Pageimage $image, int|string|null $width, int|array|null $height, array|string|int|bool|null $options = []): ?array
    {
        /** @var Config $config */
        $config = $this->wire()->config;

        if (is_string($options)) $options = ['cropping' => $options];
        else if (is_int($options)) $options = ['quality' => $options];
        else if (is_bool($options)) $options = ['upscaling' => $options];
        if (!is_array($options)) $options = [];

        if (is_array($height)) {
            $options = array_merge($options, $height);
            $height = 0;
        }

        if (!is_int($height)) $height = 0;

        if (is_string($width) && isset($config->imageSizes[$width])) {
            $size = $config->imageSizes[$width];
            $width = $size['width'] ?? 0;
            $height = ($height === 0) ? ($size['height'] ?? 0) : $height;
            $options = array_merge($size, $options);
            unset($options['width'], $options['height']);
        }

        if (!is_int($width)) $width = 0;

        if ($width > 0 && $height === 0) {
            $height = (int) round($width * ($image->height / $image->width));
        } elseif ($height > 0 && $width === 0) {
            $width = (int) round($height / ($image->height / $image->width));
        }

        if (!empty($options['insert'])) {

            if ($options['insert'] instanceof Pageimage) {
                $options['insert'] = [
                    'element' => $options['insert']->filename
                ];
            } elseif (is_string($options['insert']) && is_file($options['insert'])) {
                $options['insert'] = [
                    'element' => $options['insert']
                ];
            }

            if (!is_array($options['insert'])) $options['insert'] = [];

            $options['insert'] = array_merge([
                'element' => null,
                'position' => 'top-left',
                'offset_x' => 0,
                'offset_y' => 0,
                'opacity' => 100
            ], $options['insert']);

            if (!file_exists($options['insert']['element'])) $options['insert']['element'] = null;

            if (!is_int($options['insert']['offset_x'])) $options['insert']['offset_x'] = 0;
            if (!is_int($options['insert']['offset_y'])) $options['insert']['offset_y'] = 0;
            if (!is_int($options['insert']['opacity'])) $options['insert']['opacity'] = 100;
        }

        $options = array_merge($this->imageSizeOptions, $options);

        return [
            'width' => $width,
            'height' => $height,
            'options' => $options
        ];
    }

    /**
     * Maps ProcessWire cropping strings to Intervention v3 alignment positions.
     * 
     * @param string|bool $crop
     * @return string
     */
    protected function mapCropPosition($crop): string
    {
        if ($crop === true || $crop === 'true' || $crop === 'center') return 'center';

        $map = [
            'north' => 'top',
            'n' => 'top',
            'northwest' => 'top-left',
            'nw' => 'top-left',
            'northeast' => 'top-right',
            'ne' => 'top-right',
            'south' => 'bottom',
            's' => 'bottom',
            'southwest' => 'bottom-left',
            'sw' => 'bottom-left',
            'southeast' => 'bottom-right',
            'se' => 'bottom-right',
            'west' => 'left',
            'w' => 'left',
            'east' => 'right',
            'e' => 'right'
        ];

        return $map[strtolower((string)$crop)] ?? 'center';
    }

    /**
     * Hook: Intercepts crop()
     * 
     * @param HookEvent $event
     */
    public function hookCrop(HookEvent $event)
    {
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
    public function hookSize(HookEvent $event)
    {
        /**
         * @var Config $config
         * @var Pageimage $image
         */
        $config = $this->wire()->config;
        $image = $event->object;

        if ($image->ext === 'svg') {
            $event->return = $image;
            return;
        }

        if ($config->admin) return;

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
                return $this->create($image, $path, $width, $height, $options);
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
    public function hookSrcset(HookEvent $event)
    {
        /** @var Pageimage $image */
        $image = $event->object;

        $parameters = $this->resolveParameters(
            $image,
            $event->arguments(0),
            $event->arguments(1),
            $event->arguments(2)
        );

        $baseW = 0;
        $baseH = 0;

        if (isset($parameters['options']['baseWidth'], $parameters['options']['baseHeight'])) {
            $baseW = $parameters['options']['baseWidth'];
            $baseH = $parameters['options']['baseHeight'];
        } elseif ($parameters['width'] > 0) {
            $baseW = $parameters['width'];
            $baseH = $parameters['height'];
        } else {
            $baseW = min($image->width, $this->formatted['breakpoint']['value'] ?? 1200);
            $baseH = (int) round($baseW * ($image->height / $image->width));
        }

        $variations = [];
        $factors = $this->formatted['factors'] ?? [0.5, 1, 1.5, 2];

        $threshold = 150;
        $targetRatio = ($baseH > 0) ? ($baseW / $baseH) : ($image->width / $image->height);

        foreach ($factors as $factor) {
            $f = (float) $factor;
            $w = (int) ($baseW * $f);

            if ($w >= ($image->width - $threshold)) {
                $w = $image->width;
            }

            if ($w === $image->width) {
                $origRatio = $image->width / $image->height;
                if (abs($targetRatio - $origRatio) < 0.01) {
                    $h = $image->height;
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
    public function hookAttrs(HookEvent $event)
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

        if ($width === 0) {
            $width = min($image->width, ($this->formatted['breakpoint']['value'] ?? 1200));
            $height = (int) round($width * ($image->height / $image->width));
        }

        $source = $image->size($width, $height, $options);

        $srcsetOptions = array_merge($options, ['baseWidth' => $width, 'baseHeight' => $height]);

        $sources = [];
        // AVIF Source
        if (in_array('avifAdd', $this->options) && $source->ext !== 'avif') {
            $sources[] = [
                'type' => 'image/avif',
                'srcset' => $image->srcset(null, array_merge($srcsetOptions, ['format' => 'avif']))
            ];
        }

        // WebP Source
        if (in_array('webpAdd', $this->options) && $source->ext !== 'webp') {
            $webpSrcset = $image->srcset(null, array_merge($srcsetOptions, ['format' => 'webp']));
            $sources[] = [
                'type' => 'image/webp',
                'srcset' => $webpSrcset
            ];
        }

        $lazyload = isset($options['loading']) && is_bool($options['loading']) && $options['loading'] === true ? false : true;
        $first = !empty($options['isFirst']);

        $attrs = [
            'src' => $source->url,
            'width' => $width,
            'height' => $height,
            'alt' => $image->description ?: pathinfo($image->basename, PATHINFO_FILENAME),
            'srcset' => $image->srcset(null, $srcsetOptions),
            'sources' => $sources,
            'sizes' => $options['sizes'] ?? sprintf('(max-width: %dpx) 100vw, %dpx', $width, $width),
        ];

        // set class, style as array
        foreach (['class', 'style'] as $k) {
            $attrs[$k] = $options[$k] ?? [];
            if (is_string($attrs[$k])) {
                $attrs[$k] = [$attrs[$k]];
            }
        }

        if ($this->lqip) {
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
    public function hookRender(HookEvent $event)
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
                    'type' => $source['type']
                ];
                $sourceHtml .= "<source " . $this->attrs($sourceAttrs) . ">";
            }

            unset($attrs['srcset'], $attrs['sizes']);

            $imgHtml = "<img " . $this->attrs($attrs) . ">";
            $event->return = "<picture>{$sourceHtml}{$imgHtml}</picture>";
        } else {
            $event->return = "<img " . $this->attrs($attrs) . ">";
        }
    }

    /**
     * 404 Handler: Generates the image using Intervention v3 when requested
     * 
     * @param HookEvent $event
     */
    public function handlePageNotFound(HookEvent $event)
    {
        $url = $event->arguments(1) ?: $_SERVER['REQUEST_URI'];
        if (!$url) return;

        /**
         * @var Config $config
         * @var WireFileTools $files
         */
        $config = $event->wire()->config;
        $files = $event->wire()->files;

        if (!str_contains($url, $config->urls->files)) return;

        $root = rtrim($config->paths->root, '/\\');
        $url = parse_url($url, PHP_URL_PATH);
        $destination = $root . str_replace('/', DIRECTORY_SEPARATOR, $url);

        if (!$files->exists($destination . '.queue') || !($data = wireDecodeJSON($files->fileGetContents($destination . '.queue'), true))) return;

        try {
            $source = $config->paths->site . ltrim($data['source'], '/');

            if (!$files->exists($source)) {
                $files->unlink($destination . '.queue');
                return;
            }

            $event->replace = true;
            $event->cancelHooks = true;

            $encoded = $this->create($source, $destination, $data['width'], $data['height'], $data['options']);
            $files->unlink($destination . '.queue');

            $mime = $encoded->mimetype();
            $size = strlen((string)$encoded);

            header("Content-Type: $mime");
            header("Content-Length: $size");
            header("Cache-Control: public, max-age=31536000");

            echo $encoded;
            exit;
        } catch (\Exception $e) {
            $this->wire()->log->error("InterventionImage Error: " . $e->getMessage());
        }
    }

    /**
     * Hook: Deletes variation files when original image is deleted
     * 
     * @param HookEvent $event
     */
    public function hookDeleteVariations(HookEvent $e)
    {
        if (!$e->object instanceof Pageimage) return;
        $pi = pathinfo($e->object->filename);
        $files = glob($pi['dirname'] . '/' . $pi['filename'] . '.*');
        if ($files) {
            foreach ($files as $f) {
                if ($f !== $e->object->filename) @unlink($f);
            }
        }
    }

    /**
     * Generates a Low Quality Image Placeholder (Base64)
     * 
     * @param Pageimage $image
     * @return string
     */
    protected function generateLqip(Pageimage $image): string
    {
        $lqipPath = $image->pagefiles->path . pathinfo($image->basename, PATHINFO_FILENAME) . '.lqip.webp';

        if (!file_exists($lqipPath)) {
            try {
                if (!file_exists($image->filename)) return '';
                $this->intervention->read($image->filename)
                    ->scale(width: 100)
                    ->pixelate(6)
                    ->toWebp(20)->save($lqipPath);
            } catch (\Exception $e) {
                return '';
            }
        }

        if (file_exists($lqipPath)) {
            $data = file_get_contents($lqipPath);
            return 'data:image/jpeg;base64,' . base64_encode($data);
        }

        return '';
    }

    /**
     * Centralized image creation method.
     * Handles reading, processing, saving, and returning the result.
     * 
     * @param Pageimage|string $source Pageimage object or absolute path string
     * @param string $destination Absolute destination path
     * @param int $width Target width
     * @param int $height Target height
     * @param array $options Options array
     * 
     * @return Pageimage|EncodedImage Returns Pageimage for hooks, EncodedImage for direct output
     */
    protected function create($source, string $destination, int $width = 0, int $height = 0, array $options = [])
    {
        $filename = ($source instanceof Pageimage) ? $source->filename : $source;

        if (!file_exists($filename)) {
            throw new \Exception("InterventionImage: Source file not found: " . $filename);
        }

        $image = $this->intervention->read($filename);

        // 1. Transformations (Rotate/Flip/Flop)
        if (!empty($options['rotate'])) $image->rotate((int) $options['rotate']);
        if (!empty($options['flip'])) str_starts_with($options['flip'], 'v') ? $image->flip() : $image->flop();

        // 2. Resize / Crop Logic
        $cropping = $options['cropping'] ?? true;

        // A. Explicit Coordinates (from hook arguments)
        if (isset($options['crop_x'], $options['crop_y'])) {
            $image->crop($width, $height, (int)$options['crop_x'], (int)$options['crop_y']);
        }
        // B. String Coordinates (e.g. x100y200)
        elseif (is_string($cropping) && preg_match('/^x(\d+)y(\d+)$/i', $cropping, $m)) {
            $image->crop($width, $height, (int)$m[1], (int)$m[2]);
        }
        // C. Array Coordinates (e.g. array(100, 200) or array('50%', '50%'))
        elseif (is_array($cropping) && count($cropping) === 2) {
            $cx = str_contains((string)$cropping[0], '%')
                ? (int) ($image->width() * ((float)$cropping[0] / 100))
                : (int) $cropping[0];

            $cy = str_contains((string)$cropping[1], '%')
                ? (int) ($image->height() * ((float)$cropping[1] / 100))
                : (int) $cropping[1];

            $image->crop($width, $height, $cx, $cy);
        }
        // D. Standard Resize / Cover / Focus
        else {
            $isCover = false;
            $align = 'center';

            if (($cropping === true || $cropping === 'center') && !empty($options['focus']) && is_array($options['focus'])) {
                $align = $this->mapFocusToPosition($options['focus']);
                $isCover = true;
            } elseif ($width && $height && $cropping !== false) {
                $align = $this->mapCropPosition($cropping);
                $isCover = true;
            }

            if ($isCover) {
                if (in_array('upscale', $this->options)) {
                    $image->cover($width, $height, $align);
                } else {
                    if ($width > $image->width() || $height > $image->height()) {
                        $image->scaleDown($width, $height);
                    } else {
                        $image->cover($width, $height, $align);
                    }
                }
            } else {
                if (in_array('upscale', $this->options)) {
                    $image->scale($width ?: null, $height ?: null);
                } else {
                    $image->scaleDown($width ?: null, $height ?: null);
                }
            }
        }

        // 3. Effects
        if (($options['sharpening'] ?? 'soft') !== 'none') {
            $amount = ['medium' => 20, 'strong' => 40, 'soft' => 10][$options['sharpening']] ?? 10;
            $image->sharpen($amount);
        }

        if (isset($options['brightness']) && is_numeric($options['brightness'])) {
            $image->brightness((int)$options['brightness']);
        }

        if (isset($options['contrast']) && is_numeric($options['contrast'])) {
            $image->contrast((int)$options['contrast']);
        }

        if (isset($options['gamma']) && is_numeric($options['gamma'])) {
            $image->gamma(floatval($options['gamma']));
        }

        if (!empty($options['colorize'])) {
            $colorize = $this->normalizeColorize($options['colorize']);
            if ($colorize[0] !== 0 || $colorize[1] !== 0 || $colorize[2] !== 0) {
                $image->colorize(...$colorize);
            }
        }

        if (!empty($options['grayscale'])) $image->greyscale();
        // if (!empty($options['flop'])) $image->flop();
        // if (!empty($options['flip'])) $image->flip();
        // if (!empty($options['rotate'])) $image->rotate((int) $options['rotate']);
        if (!empty($options['blur'])) $image->blur((int) $options['blur']);
        if (!empty($options['sharpen'])) $image->sharpen((int) $options['sharpen']);
        if (!empty($options['invert'])) $image->invert();
        if (!empty($options['pixelate'])) $image->pixelate((int) $options['pixelate']);

        // Insert Images
        if (!empty($options['insert'])) $image->place(...$options['insert']);

        $baseQuality = $options['quality'] ?? 90;

        if (str_ends_with($destination, '.webp')) {
            $q = $this->imageSizeOptions['webp']['quality'] ?? $baseQuality;
            $encoded = $image->toWebp($q);
        } elseif (str_ends_with($destination, '.avif')) {
            $q = $this->imageSizeOptions['avif']['quality'] ?? $baseQuality;
            $encoded = $image->toAvif($q);
        } elseif (str_ends_with($destination, '.png')) {
            $encoded = $image->toPng();
        } elseif (str_ends_with($destination, '.gif')) {
            $encoded = $image->toGif();
        } else {
            $encoded = $image->toJpeg($baseQuality);
        }

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
        if ($this->imageSizeOptions['avifOnly'] || ($params['options']['format'] ?? '') === 'avif') $ext = 'avif';
        elseif ($this->imageSizeOptions['webpOnly'] || ($params['options']['format'] ?? '') === 'webp') $ext = 'webp';
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
        $files->filePutContents($destPath . ".queue", json_encode($data), LOCK_EX);
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
            foreach ($list as $s) if ($c = $sanitizer->fieldName($s)) $parts[] = $c;
        }

        // Standard Params
        if (!empty($options['rotate'])) $parts[] = 'rot' . (int) $options['rotate'];
        if (!empty($options['flip'])) $parts[] = 'flip' . substr($options['flip'], 0, 1);
        if (!empty($options['hidpi'])) $parts[] = 'hidpi';

        // Crop Info
        if (isset($options['crop_x'], $options['crop_y'])) $parts[] = "c{$options['crop_x']}x{$options['crop_y']}";
        elseif (!empty($options['cropping']) && is_string($options['cropping']) && !in_array($options['cropping'], ['center', 'true'])) {
            $parts[] = $sanitizer->fieldName($options['cropping']);
        }

        // insert
        if (isset($options['insert']) && $options['insert']['element']) {
            $insert = md5($options['insert']['element']);
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
        if (!empty($options['gamma'])) $parts[] = 'gam' . (float) $options['gamma'];

        // Brightness (e.g. -bright10)
        if (!empty($options['brightness'])) $parts[] = 'bri' . (float) $options['brightness'];

        // Contrast (e.g. -con10)
        if (!empty($options['contrast'])) $parts[] = 'con' . (float) $options['contrast'];

        // Gamma (e.g. -gam10)
        if (!empty($options['gamma'])) $parts[] = 'gam' . (float) $options['gamma'];

        // Colorize (e.g. -col10-0-0)
        if (!empty($options['colorize'])) {
            [$cr, $cg, $cb] = $this->normalizeColorize($options['colorize']);
            if ($cr !== 0 || $cg !== 0 || $cb !== 0) {
                $parts[] = "col{$cr}-{$cg}-{$cb}";
            }
        }

        // Greyscale (e.g. -gr)
        if (!empty($options['greyscale'])) $parts[] = 'gre';

        // Flop (e.g. -flop)
        if (!empty($options['flop'])) $parts[] = 'flop';

        // Flip (e.g. -flip)
        // if (!empty($options['flip'])) $parts[] = 'flip';

        // Blur (e.g. -blu10)
        if (!empty($options['blur'])) $parts[] = 'blu' . (int) $options['blur'];

        // Rotate (e.g. -rot10)
        // if (!empty($options['rotate'])) $parts[] = 'rot' . (int) $options['rotate'];

        // Sharpen (e.g. -sha10)
        if (!empty($options['sharpen'])) $parts[] = 'sha' . (int) $options['sharpen'];

        // Invert (e.g. -inv)
        if (!empty($options['invert'])) $parts[] = 'inv';

        // Pixelate (e.g. -pix10)
        if (!empty($options['pixelate'])) $parts[] = 'pix' . (int) $options['pixelate'];

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
            (int) $red,
            (int) $green,
            (int) $blue
        ];
    }

    /**
     * Maps Focus Point percentages to Intervention v3 alignment positions.
     * 
     * @param array $focus [top%, left%] e.g., [20, 50]
     * @return string
     */
    protected function mapFocusToPosition(array $focus): string
    {
        $top = (float) ($focus[0] ?? 50);
        $left = (float) ($focus[1] ?? 50);

        // Y
        $v = 'center';
        if ($top < 33) $v = 'top';
        elseif ($top > 66) $v = 'bottom';

        // X
        $h = 'center';
        if ($left < 33) $h = 'left';
        elseif ($left > 66) $h = 'right';

        if ($v === 'center' && $h === 'center') return 'center';
        if ($v === 'center') return $h; // left, right
        if ($h === 'center') return $v; // top, bottom

        return "$v-$h"; // top-left, bottom-right etc.
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
            if ($value === null || $value === false || is_string($value) && !strlen($value)) {
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
    public function getModuleConfigInputfields(InputfieldWrapper $inputfields)
    {
        $modules = $this->wire()->modules;
        $fs = $modules->get('InputfieldFieldset');
        $fs->name = 'intervention';
        $fs->label = __('Settings');
        $inputfields->add($fs);

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
            'avifAdd' => __('Add AVIF format to picture tags'),
            'webpAdd' => __('Add WebP format to picture tags')
        ]);
        $f->value = is_array($this->options) ? $this->options : [];
        $f->columnWidth = 50;
        $fs->add($f);

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

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'breakpoints';
        $f->label = __('Breakpoints');
        $f->description = __('Add breakpoints for responsive images. One per line.');
        $f->value = $this->breakpoints ?: $this->defaults['breakpoints'];
        $f->notes = __('Breakpoints should be in the format `value=key|Label`. For set default breakpoint `value=+key|Label` format.');
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'aspectRatios';
        $f->label = __('Aspect Ratios');
        $f->description = __('Add aspect ratios for responsive images. One per line.');
        $f->notes = __('Aspect ratios should be in the format `width:height=key|Label`. For set default aspect ratio `width:height=+key|Label` format.');
        $f->value = $this->aspectRatios ?: $this->defaults['aspectRatios'];
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'columnWidths';
        $f->label = __('Grid Columns');
        $f->description = __('Comma-separated list of grid columns for responsive images.');
        $f->value = $this->columnWidths ?: $this->defaults['columnWidths'];
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'factors';
        $f->label = __('Responsive image resizing factors');
        $f->description = __('Comma-separated list of responsive image resizing factors.');
        $f->value = $this->factors ?: $this->defaults['factors'];
        $f->columnWidth = 50;
        $fs->add($f);
    }
}
