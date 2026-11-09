<?php

declare(strict_types=1);

namespace ProcessWire;

use Intervention\Image\Alignment;
use Intervention\Image\Colors\Theme;
use Intervention\Image\Colors\Themes\VibrantMuted\Theme as VibrantMutedTheme;
use Intervention\Image\Direction;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\AnalyzerInterface;
use Intervention\Image\Interfaces\ColorInterface;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ModifierInterface;
use Intervention\ImageHash\Analyzers\ImageHashAnalyzer;
use Intervention\ImageHash\Hash;
use Intervention\ImageHash\Interfaces\StrategyInterface;
use Intervention\ImageHash\Strategies\AverageStrategy;
use Intervention\ImageHash\Strategies\BlockStrategy;
use Intervention\ImageHash\Strategies\DifferenceStrategy;
use Intervention\ImageHash\Strategies\PerceptualStrategy;

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
            'version' => 121,
            'summary' => __('Replaces PW sizing with Intervention Image v4.4 (GD, Imagick, libvips) + Delayed Rendering + Perceptual ImageHash.'),
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
        require_once __DIR__ . '/vendor/autoload.php';
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
                if ($event->wire()->config->admin || !is_string($event->return)) {
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

        // Perceptual hashing hooks (Intervention ImageHash v1.0)
        $this->addHookMethod('Pageimage::imageHash', $this, 'hookImageHash');
        $this->addHookMethod('Pageimage::hashDistance', $this, 'hookHashDistance');
        $this->addHookMethod('Pageimage::isSimilar', $this, 'hookIsSimilar');

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
     * Checks whether a given Intervention Image driver class is available and passes its runtime health check.
     *
     * @param class-string<DriverInterface> $driverClass
     * @return bool
     */
    protected function isDriverHealthy(string $driverClass): bool
    {
        if (!class_exists($driverClass)) {
            return false;
        }

        try {
            /** @var DriverInterface $driver */
            $driver = new $driverClass();
            $driver->checkHealth();
            return true;
        } catch (\Throwable) {
            return false;
        }
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
        $driverMap = [
            'vips' => VipsDriver::class,
            'imagick' => ImagickDriver::class,
            'gd' => GdDriver::class,
        ];

        $driverClass = null;

        if (isset($driverMap[$selectedDriver]) && $this->isDriverHealthy($driverMap[$selectedDriver])) {
            $driverClass = $driverMap[$selectedDriver];
        }

        if ($driverClass === null) {
            foreach ([VipsDriver::class, ImagickDriver::class, GdDriver::class] as $candidateClass) {
                if ($this->isDriverHealthy($candidateClass)) {
                    $driverClass = $candidateClass;
                    break;
                }
            }
        }

        if ($driverClass === null) {
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
     * @return array{default: array<string, mixed>, data: array<string, array<string, mixed>>}
     */
    protected function multilineConfigToArray(string $str): array
    {
        $config = ['default' => [], 'data' => []];
        $lines = array_map('trim', explode("\n", str_replace("\r", "", $str)));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

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
     * @return array{width: int, height: int, maxWidth: int, ratio: float}
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
        if ($ratioKey !== null && $ratioKey !== '') {
            $w = ($width > 0) ? min($width, $maxWidth) : $maxWidth;
            $h = (int) ceil($w * $ratio);
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
     * Normalizes shorthand scalar options (string cropping, int quality, bool upscaling) into an associative array.
     *
     * @param mixed $options
     * @return array<string, mixed>
     */
    protected function normalizeScalarOptions(mixed $options): array
    {
        return match (true) {
            is_array($options) => $options,
            is_string($options) => ['cropping' => $options],
            is_int($options) => ['quality' => $options],
            is_bool($options) => ['upscaling' => $options],
            default => [],
        };
    }

    /**
     * Resolves parameters
     * 
     * @param Pageimage $image
     * @param int|string|null $width
     * @param int|string|array<string, mixed>|null $height
     * @param array<string, mixed>|string|int|bool|null $options
     * 
     * @return array{width: int, height: int, options: array<string, mixed>}
     */
    protected function resolveParameters(Pageimage $image, int|string|null $width, int|string|array|null $height, array|string|int|bool|null $options = []): array
    {
        /** @var Config $config */
        $config = $this->wire()->config;
        $options = $this->normalizeScalarOptions($options);

        if (is_array($height)) {
            $options = array_merge($options, $height);
            $height = 0;
        } elseif (is_string($height) && ctype_digit(trim($height))) {
            $height = (int) trim($height);
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

        if (($width > 0 && $height === 0) || ($height > 0 && $width === 0)) {
            $iw = max(1, (int) $image->width);
            $ih = max(1, (int) $image->height);
            if ($width > 0) {
                $height = (int) round($width * ($ih / $iw));
            } else {
                $width = (int) round($height / ($ih / $iw));
            }
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

        $croppingMode = $options['cropping'] ?? true;
        $usesCenterFocus = ($croppingMode === true || $croppingMode === 'center') && !isset($options['crop_x'], $options['crop_y']) && empty($options['contain']);
        if ($usesCenterFocus && $image->hasFocus && (empty($options['focus']) || !is_array($options['focus']))) {
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
        if ($event->wire()->config->admin) {
            return;
        }

        /** @var Pageimage $image */
        $image = $event->object;

        $x = (int) $event->arguments(0);
        $y = (int) $event->arguments(1);
        $width = $event->arguments(2);
        $height = $event->arguments(3);
        $options = $this->normalizeScalarOptions($event->arguments(4));

        if (is_array($height)) {
            $options = array_merge($height, $options);
            $height = 0;
        }

        $options['crop_x'] = $x;
        $options['crop_y'] = $y;

        $event->replace = true;
        $event->return = $image->size($width, $height, $options);
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
        $config = $event->wire()->config;
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
        $event->return = $this->createVariationInstance($image, $path);
    }

    /**
     * Clones a Pageimage instance and binds it to the generated variation path.
     *
     * @param Pageimage $image
     * @param string $path
     * @return Pageimage
     */
    protected function createVariationInstance(Pageimage $image, string $path): Pageimage
    {
        $variation = clone $image;
        $variation->setOriginal($image->getOriginal() ?: $image);
        $variation->setFilename($path);
        return $variation;
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
            $lazyload = (bool) $this->lazyload;
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

        if ($this->lazyload && $lazyload && !in_array('lazyload', $attrs['class'], true)) {
            $attrs['class'][] = 'lazyload';
        }

        if ($this->lqip && !$isSvg) {
            $base64 = $this->generateLqip($image);
            if ($base64 !== '') {
                if (!in_array('lazyload', $attrs['class'], true)) {
                    $attrs['class'][] = 'lazyload';
                }
                $attrs['style'][] = 'background-image: url("' . $base64 . '"); background-size: cover; background-position: center;';
            }
        }

        $attrs['loading'] = $lazyload ? 'lazy' : 'eager';
        $decoding = $options['decoding'] ?? null;
        $attrs['decoding'] = is_string($decoding) && in_array($decoding, ['sync', 'async', 'auto'], true)
            ? $decoding
            : ($lazyload ? 'async' : 'sync');

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
        $rootUrl = $config->urls->root;
        if ($rootUrl !== '/' && str_starts_with($urlPath, $rootUrl)) {
            $urlPath = '/' . ltrim(substr($urlPath, strlen($rootUrl)), '/');
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
            $event->wire()->log->error('InterventionImage Error: ' . $e->getMessage());
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

        $data = $this->readSidecarJson($queueFile);
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
     * @param HookEvent $event
     */
    public function hookDeleteVariations(HookEvent $event): void
    {
        if (!$event->object instanceof Pageimage) {
            return;
        }
        $pi = pathinfo($event->object->filename);
        $prefix = $pi['filename'] . '.';
        $dir = $pi['dirname'];

        if (!is_dir($dir)) {
            return;
        }

        $files = $event->wire()->files;

        try {
            $it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME);
            foreach ($it as $path) {
                $base = basename($path);
                if (str_starts_with($base, $prefix) && $path !== $event->object->filename) {
                    $files->unlink($path);
                }
            }
        } catch (\Throwable $e) {
            if ($event->wire()->config->debug) {
                $event->wire()->log->warning('InterventionImage variation delete warning: ' . $e->getMessage());
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
     * Resolves a target Page, Page ID, or sanitized Page path into its files directory,
     * or returns the root site/assets/files directory when $page is null or empty.
     *
     * @param Page|int|string|null $page
     * @return string
     */
    public function resolveTargetDirectory(Page|int|string|null $page = null): string
    {
        $config = $this->wire()->config;
        if ($page === null || $page === '') {
            return rtrim($config->paths->files, '/\\');
        }

        if (!$page instanceof Page) {
            $pages = $this->wire()->pages;
            $sanitizer = $this->wire()->sanitizer;
            $raw = (string) $page;

            $page = ctype_digit($raw)
                ? $pages->get((int) $raw)
                : $pages->get($sanitizer->selectorValue($raw));
        }

        if (!$page instanceof Page || !$page->id) {
            throw new \InvalidArgumentException('InterventionImage: Target page not found.');
        }

        return rtrim($page->filesManager()->path(), '/\\');
    }

    /**
     * Recursively finds all pending `.queue` sidecar files in the given directory.
     *
     * @param string $directory
     * @return list<string>
     */
    protected function findQueueFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.queue')) {
                $found[] = $fileInfo->getPathname();
            }
        }

        sort($found);
        return $found;
    }

    /**
     * Inspects pending `.queue` sidecar files globally or for a specific Page.
     *
     * @param Page|int|string|null $page
     * @param int $limit
     * @return array{total: int, items: list<array{target: string, dimensions: string, source: string}>}
     */
    public function inspectQueue(Page|int|string|null $page = null, int $limit = 50): array
    {
        $baseDir = $this->resolveTargetDirectory($page);
        $queueFiles = $this->findQueueFiles($baseDir);
        $total = count($queueFiles);
        $filesPath = $this->wire()->config->paths->files;

        $items = [];
        foreach (array_slice($queueFiles, 0, max(1, $limit)) as $qFile) {
            $data = $this->readSidecarJson($qFile) ?? [];
            $items[] = [
                'target' => str_replace($filesPath, '', substr($qFile, 0, -6)),
                'dimensions' => ($data['width'] ?? 0) . 'x' . ($data['height'] ?? 0),
                'source' => (string) ($data['source'] ?? ''),
            ];
        }

        return [
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * Processes a batch of pending `.queue` sidecar files globally or for a specific Page.
     *
     * @param Page|int|string|null $page
     * @param int $limit
     * @return array{processed: int, failed: int, remaining: int, errors: list<string>}
     */
    public function processQueue(Page|int|string|null $page = null, int $limit = 50): array
    {
        $baseDir = $this->resolveTargetDirectory($page);
        $queueFiles = $this->findQueueFiles($baseDir);
        $batch = array_slice($queueFiles, 0, max(1, $limit));

        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($batch as $qFile) {
            try {
                $encoded = $this->processQueueFile($qFile);
                if ($encoded instanceof EncodedImageInterface) {
                    $processed++;
                } else {
                    $failed++;
                    $errors[] = basename($qFile) . ' — Queue payload or source file is invalid.';
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = basename($qFile) . ' — ' . $e->getMessage();
            }
        }

        $remaining = count($this->findQueueFiles($baseDir));

        return [
            'processed' => $processed,
            'failed' => $failed,
            'remaining' => $remaining,
            'errors' => $errors,
        ];
    }

    /**
     * Clears generated image variations, LQIP files (.lqip.webp), color metadata (.colors.json),
     * perceptual hash caches (.hashes.json), and delayed queue files (.queue) either globally ($page = null)
     * or for a specific Page.
     *
     * @param Page|int|string|null $page Page object, Page ID, Page path, or null for all pages
     * @param array{variations?: bool, lqip?: bool, colors?: bool, hashes?: bool, queue?: bool, dryRun?: bool} $options
     * @return array{variations: int, lqip: int, colors: int, hashes: int, queue: int, total: int, bytes: int}
     */
    public function clearVariations(Page|int|string|null $page = null, array $options = []): array
    {
        $files = $this->wire()->files;

        $counts = [
            'variations' => 0,
            'lqip' => 0,
            'colors' => 0,
            'hashes' => 0,
            'queue' => 0,
            'total' => 0,
            'bytes' => 0,
        ];

        $baseDir = $this->resolveTargetDirectory($page);

        if (!is_dir($baseDir)) {
            return $counts;
        }

        $hasSpecificFilter = array_key_exists('variations', $options)
            || array_key_exists('lqip', $options)
            || array_key_exists('colors', $options)
            || array_key_exists('hashes', $options)
            || array_key_exists('queue', $options);

        $clearVariations = $hasSpecificFilter ? !empty($options['variations']) : true;
        $clearLqip = $hasSpecificFilter ? !empty($options['lqip']) : true;
        $clearColors = $hasSpecificFilter ? !empty($options['colors']) : true;
        $clearHashes = $hasSpecificFilter ? !empty($options['hashes']) : true;
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
            } elseif (str_ends_with($filename, '.hashes.json')) {
                if ($clearHashes) {
                    $category = 'hashes';
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
        $event->return = $image->size($width, $height, $options);
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
        $argWidth = $event->arguments(5);
        $argHeight = $event->arguments(6);
        $bothOmitted = empty($argWidth) && empty($argHeight);
        $width = $bothOmitted ? (int) $image->width : ($argWidth ?: 0);
        $height = $bothOmitted ? (int) $image->height : ($argHeight ?: 0);
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
     * Resolves the original Pageimage and verifies that it is a non-empty raster file on disk.
     *
     * @param Pageimage $image
     * @return Pageimage|null
     */
    protected function resolveRasterSource(Pageimage $image): ?Pageimage
    {
        $source = $image->getOriginal() ?: $image;
        if ($this->isSvg($source) || !file_exists($source->filename) || filesize($source->filename) === 0) {
            return null;
        }
        return $source;
    }

    /**
     * Builds the absolute path for a sidecar cache file next to the original Pageimage.
     *
     * @param Pageimage $source
     * @param string $suffix Sidecar suffix including leading dot (e.g. `.colors.json`, `.hashes.json`, `.lqip.webp`)
     * @return string
     */
    protected function getSidecarPath(Pageimage $source, string $suffix): string
    {
        return $source->pagefiles->path() . pathinfo($source->basename, PATHINFO_FILENAME) . $suffix;
    }

    /**
     * Reads and decodes a JSON sidecar cache file if it exists on disk.
     *
     * @param string $path
     * @return array<string, mixed>|null
     */
    protected function readSidecarJson(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        $decoded = wireDecodeJSON((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Atomically writes an associative array to a JSON sidecar cache file.
     *
     * @param string $path
     * @param array<string, mixed> $data
     */
    protected function writeSidecarJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (is_string($json)) {
            $this->wire()->files->filePutContents($path, $json, LOCK_EX);
        }
    }

    /**
     * Formats a 6-character hexadecimal color string with or without a leading `#`.
     *
     * @param string $hex
     * @param bool $prefix
     * @return string
     */
    protected function formatHexColor(string $hex, bool $prefix): string
    {
        $clean = ltrim($hex, '#');
        return $prefix ? ('#' . $clean) : $clean;
    }

    /**
     * Logs an exception message to the ProcessWire error log when debug mode is enabled.
     *
     * @param string $context
     * @param \Throwable $e
     */
    protected function logDebugError(string $context, \Throwable $e): void
    {
        if ($this->wire()->config->debug) {
            $this->wire()->log->error("InterventionImage {$context} Error: " . $e->getMessage());
        }
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

        $meta = $this->extractColorMetadata($image, 1);
        $hex = $meta['dominant'] ?? '';
        if ($hex === '') {
            $event->return = '';
            return;
        }

        $event->return = $this->formatHexColor($hex, $prefix);
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
            fn(string $hex): string => $this->formatHexColor($hex, $prefix),
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

        $meta = $this->extractColorMetadata($image, 1);
        $theme = $meta['theme'] ?? [];

        $formatted = [];
        foreach ($theme as $swatch => $hex) {
            $formatted[$swatch] = is_string($hex) && $hex !== ''
                ? $this->formatHexColor($hex, $prefix)
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
        $source = $this->resolveRasterSource($image);
        if (!$source instanceof Pageimage) {
            return ['dominant' => '', 'palette' => [], 'theme' => []];
        }

        $requestedLimit = max(8, $paletteLimit);
        $cachePath = $this->getSidecarPath($source, '.colors.json');
        $cached = $this->readSidecarJson($cachePath);
        if (
            is_array($cached)
            && isset($cached['dominant'], $cached['palette'], $cached['theme'])
            && is_array($cached['palette'])
            && (count($cached['palette']) >= $paletteLimit || (int) ($cached['limit'] ?? 8) >= $paletteLimit)
        ) {
            /** @var array{dominant: string, palette: list<string>, theme: array<string, ?string>} $cached */
            return $cached;
        }

        try {
            $sample = $this->intervention->decodePath($source->filename)->scaleDown(width: 32, height: 32);
            $extractor = $sample->colors();

            $dominantPalette = $extractor->dominant($requestedLimit);
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
                'limit' => $requestedLimit,
            ];

            $this->writeSidecarJson($cachePath, $data);

            return $data;
        } catch (\Throwable $e) {
            $this->logDebugError('ColorExtractor', $e);
            return ['dominant' => '', 'palette' => [], 'theme' => []];
        }
    }

    /**
     * Hook: Pageimage::imageHash(string|StrategyInterface|AnalyzerInterface $strategy = 'dhash')
     * Computes and caches the perceptual hexadecimal fingerprint of a Pageimage.
     */
    public function hookImageHash(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;
        $strategy = $event->arguments(0) ?? 'dhash';

        $event->return = $this->extractImageHash($image, $strategy);
    }

    /**
     * Hook: Pageimage::hashDistance(Pageimage|string|Hash $other, string|StrategyInterface|AnalyzerInterface $strategy = 'dhash')
     * Calculates the Hamming distance between this Pageimage and another Pageimage, file path, or hexadecimal hash string.
     */
    public function hookHashDistance(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;
        $other = $event->arguments(0);
        $strategy = $event->arguments(1) ?? 'dhash';

        $event->return = $this->calculateHashDistance($image, $other, $strategy);
    }

    /**
     * Hook: Pageimage::isSimilar(Pageimage|string|Hash $other, int $maxDistance = 5, string|StrategyInterface|AnalyzerInterface $strategy = 'dhash')
     * Determines whether this Pageimage is visually similar to another Pageimage, file path, or hexadecimal hash string.
     */
    public function hookIsSimilar(HookEvent $event): void
    {
        /** @var Pageimage $image */
        $image = $event->object;
        $other = $event->arguments(0);
        $maxDistance = (int) ($event->arguments(1) ?? 5);
        $strategy = $event->arguments(2) ?? 'dhash';

        $distance = $this->calculateHashDistance($image, $other, $strategy);
        $event->return = $distance >= 0 && $distance <= max(0, $maxDistance);
    }

    /**
     * Resolves a perceptual hash strategy identifier or instance into a cache key and AnalyzerInterface.
     *
     * @param string|StrategyInterface|AnalyzerInterface $strategy
     * @return array{key: string, analyzer: AnalyzerInterface}
     */
    protected function resolveHashStrategy(string|StrategyInterface|AnalyzerInterface $strategy = 'dhash'): array
    {
        if ($strategy instanceof StrategyInterface && $strategy instanceof AnalyzerInterface) {
            $key = match (true) {
                $strategy instanceof DifferenceStrategy => 'dhash',
                $strategy instanceof PerceptualStrategy => 'phash',
                $strategy instanceof AverageStrategy => 'ahash',
                $strategy instanceof BlockStrategy => 'bhash',
                default => 'custom_' . substr(md5($strategy::class), 0, 8),
            };
            return ['key' => $key, 'analyzer' => $strategy];
        }

        if ($strategy instanceof StrategyInterface) {
            return [
                'key' => 'custom_' . substr(md5($strategy::class), 0, 8),
                'analyzer' => new ImageHashAnalyzer($strategy),
            ];
        }

        if ($strategy instanceof AnalyzerInterface) {
            return [
                'key' => 'custom_' . substr(md5($strategy::class), 0, 8),
                'analyzer' => $strategy,
            ];
        }

        $normalized = strtolower(trim($strategy));
        return match ($normalized) {
            '', 'dhash', 'difference' => ['key' => 'dhash', 'analyzer' => new DifferenceStrategy()],
            'phash', 'perceptual' => ['key' => 'phash', 'analyzer' => new PerceptualStrategy()],
            'ahash', 'average' => ['key' => 'ahash', 'analyzer' => new AverageStrategy()],
            'bhash', 'block' => ['key' => 'bhash', 'analyzer' => new BlockStrategy()],
            default => throw new \InvalidArgumentException("InterventionImage: Unsupported perceptual hash strategy '{$strategy}'."),
        };
    }

    /**
     * Extracts and caches the perceptual hash for a Pageimage in `{basename}.hashes.json`.
     *
     * @param Pageimage $image
     * @param string|StrategyInterface|AnalyzerInterface $strategy
     * @return string
     */
    protected function extractImageHash(Pageimage $image, string|StrategyInterface|AnalyzerInterface $strategy = 'dhash'): string
    {
        $source = $this->resolveRasterSource($image);
        if (!$source instanceof Pageimage) {
            return '';
        }

        $resolved = $this->resolveHashStrategy($strategy);
        $cacheKey = $resolved['key'];
        $cachePath = $this->getSidecarPath($source, '.hashes.json');

        $cachedHashes = $this->readSidecarJson($cachePath) ?? [];
        $existing = $cachedHashes[$cacheKey] ?? null;
        if (is_string($existing) && $existing !== '' && ctype_xdigit($existing)) {
            return $existing;
        }

        try {
            $hashResult = $this->intervention
                ->decodePath($source->filename)
                ->analyze($resolved['analyzer']);

            $hex = $hashResult instanceof Hash ? $hashResult->toHex() : (string) $hashResult;
            if ($hex === '' || !ctype_xdigit($hex)) {
                return '';
            }

            $cachedHashes[$cacheKey] = $hex;
            $this->writeSidecarJson($cachePath, $cachedHashes);

            return $hex;
        } catch (\Throwable $e) {
            $this->logDebugError('ImageHash', $e);
            return '';
        }
    }

    /**
     * Resolves a target Pageimage, file path, hexadecimal hash string, or Hash object into a Hash instance.
     *
     * @param mixed $target
     * @param string|StrategyInterface|AnalyzerInterface $strategy
     * @return Hash|null
     */
    protected function resolveTargetHash(mixed $target, string|StrategyInterface|AnalyzerInterface $strategy = 'dhash'): ?Hash
    {
        if ($target instanceof Hash) {
            return $target;
        }

        if ($target instanceof Pageimage) {
            $hex = $this->extractImageHash($target, $strategy);
            return $hex !== '' ? Hash::fromHex($hex) : null;
        }

        if (!is_string($target) || trim($target) === '') {
            return null;
        }

        $trimmed = ltrim(trim($target), '#');

        if (!is_file($target) && strlen($trimmed) >= 2 && strlen($trimmed) % 2 === 0 && ctype_xdigit($trimmed)) {
            return Hash::fromHex($trimmed);
        }

        if (is_file($target) && filesize($target) > 0) {
            $resolved = $this->resolveHashStrategy($strategy);
            try {
                $result = $this->intervention->decodePath($target)->analyze($resolved['analyzer']);
                return $result instanceof Hash ? $result : Hash::fromHex((string) $result);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Calculates the Hamming distance between a Pageimage and another target.
     *
     * @param Pageimage $image
     * @param mixed $other
     * @param string|StrategyInterface|AnalyzerInterface $strategy
     * @return int
     */
    protected function calculateHashDistance(Pageimage $image, mixed $other, string|StrategyInterface|AnalyzerInterface $strategy = 'dhash'): int
    {
        $sourceHex = $this->extractImageHash($image, $strategy);
        if ($sourceHex === '') {
            throw new \InvalidArgumentException('InterventionImage: Unable to compute perceptual hash for source image.');
        }

        $sourceHash = Hash::fromHex($sourceHex);
        $targetHash = $this->resolveTargetHash($other, $strategy);

        if (!$targetHash instanceof Hash) {
            throw new \InvalidArgumentException('InterventionImage: Invalid comparison target for perceptual hash distance.');
        }

        return $sourceHash->distance($targetHash);
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
        $source = $this->resolveRasterSource($image);
        if (!$source instanceof Pageimage) {
            return '';
        }

        $lqipPath = $this->getSidecarPath($source, '.lqip.webp');

        if (!file_exists($lqipPath)) {
            try {
                $encoded = $this->intervention->decodePath($source->filename)
                    ->scaleDown(width: 24)
                    ->blur(2)
                    ->encodeUsingFormat(Format::WEBP, quality: 20, strip: true);

                $encoded->save($lqipPath);
                return (string) $encoded->toDataUri();
            } catch (\Throwable $e) {
                $this->logDebugError('LQIP', $e);
                return '';
            }
        }

        $data = file_get_contents($lqipPath);
        if ($data !== false) {
            return 'data:image/webp;base64,' . base64_encode($data);
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
        elseif ($cropping !== false) {
            $align = $this->mapCropPosition($cropping);
            $upscale
                ? $image->cover($width, $height, $align)
                : $image->coverDown($width, $height, $align);
        } else {
            $upscale
                ? $image->scale($width, $height)
                : $image->scaleDown($width, $height);
        }

        // Fill transparent areas if background color is explicitly specified
        if ($background !== null && !empty($options['fillTransparent'])) {
            $image->fillTransparentAreas($background);
        }

        // 3. Effects — all values clamped per Intervention v4.4 contracts
        $sharpenAmount = $this->resolveSharpenAmount($options);
        if ($sharpenAmount > 0) {
            $image->sharpen($sharpenAmount);
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
        if (!empty($options['invert'])) {
            $image->invert();
        }
        if (!empty($options['pixelate'])) {
            $image->pixelate(max(1, (int) $options['pixelate']));
        }
        if (!empty($options['reduceColors'])) {
            $maxColors = max(1, min(256, (int) $options['reduceColors']));
            try {
                $image->reduceColors($maxColors, $background);
            } catch (\Intervention\Image\Exceptions\NotSupportedException) {
                $native = $image->core()->native();
                if ($native instanceof \Jcupitt\Vips\Image) {
                    $levels = max(2, min(16, (int) round($maxColors ** (1 / 3))));
                    $step = 255.0 / ($levels - 1);
                    $quantized = $native->linear(1.0 / $step, 0.0)->rint()->linear($step, 0.0)->cast($native->format);
                    $image->core()->setNative($quantized);
                }
            }
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
            return $this->createVariationInstance($source, $destination);
        }

        return $encoded;
    }

    /**
     * Resolves the effective sharpening amount (0-100) from `sharpen` (explicit numeric) or `sharpening` (preset).
     * Explicit numeric `sharpen` takes precedence over the `sharpening` preset.
     *
     * @param array<string, mixed> $options
     * @return int
     */
    protected function resolveSharpenAmount(array $options): int
    {
        if (isset($options['sharpen']) && is_numeric($options['sharpen'])) {
            return max(0, min(100, (int) $options['sharpen']));
        }

        $preset = strtolower(trim((string) ($options['sharpening'] ?? 'soft')));
        if ($preset === 'none' || $preset === '' || $preset === 'false' || $preset === '0') {
            return 0;
        }

        if (is_numeric($preset)) {
            return max(0, min(100, (int) $preset));
        }

        return match ($preset) {
            'medium' => 20,
            'strong' => 40,
            default => 10,
        };
    }

    /**
     * Helper: Determines file path and format based on configuration
     * 
     * @param Pageimage $image
     * @param array{width: int, height: int, options: array<string, mixed>} $params
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
     * @param array{width: int, height: int, options: array<string, mixed>} $params
     * 
     * @return void
     */
    protected function createQueue(Pageimage $source, string $destPath, array $params): void
    {
        $src = str_replace($this->wire()->config->paths->site, '', $source->filename);
        $this->writeSidecarJson($destPath . '.queue', [
            'source' => $src,
            'width' => $params['width'],
            'height' => $params['height'],
            'options' => $params['options'],
        ]);
    }

    /**
     * Creates filename suffix conforming to ProcessWire standards
     * Standard: .widthxheight[-suffix].ext
     * 
     * @param array{width: int, height: int, options: array<string, mixed>} $params
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
                $cleaned = $sanitizer->fieldName($s);
                if ($cleaned !== '') {
                    $parts[] = $cleaned;
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
        $cropping = $options['cropping'] ?? true;
        if (isset($options['crop_x'], $options['crop_y'])) {
            $parts[] = "c{$options['crop_x']}x{$options['crop_y']}";
        } elseif (!empty($options['contain']) || $cropping === 'contain') {
            $parts[] = 'fit';
        } elseif (is_array($cropping) && count($cropping) === 2) {
            $cx = $sanitizer->fieldName((string) $cropping[0]);
            $cy = $sanitizer->fieldName((string) $cropping[1]);
            $parts[] = "c{$cx}x{$cy}";
        } elseif (($cropping === true || $cropping === 'center') && !empty($options['focus']) && is_array($options['focus'])) {
            $ft = (int) round((float) ($options['focus']['top'] ?? $options['focus'][0] ?? 50));
            $fl = (int) round((float) ($options['focus']['left'] ?? $options['focus'][1] ?? 50));
            $fz = (int) ($options['focus']['zoom'] ?? $options['focus'][2] ?? 0);
            if ($ft !== 50 || $fl !== 50 || $fz > 0) {
                $parts[] = "f{$ft}x{$fl}" . ($fz > 0 ? "z{$fz}" : '');
            }
        } elseif (!empty($cropping) && is_string($cropping) && !in_array($cropping, ['center', 'true'], true)) {
            $parts[] = $sanitizer->fieldName($cropping);
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

        // Sharpen (e.g. -sha20 or -sha0 when sharpening is explicitly overridden from default 'soft')
        $sharpenAmount = $this->resolveSharpenAmount($options);
        $hasExplicitSharpen = isset($options['sharpen']) && is_numeric($options['sharpen']) && (int) $options['sharpen'] > 0;
        $hasCustomPreset = isset($options['sharpening']) && strtolower(trim((string) $options['sharpening'])) !== 'soft';
        if ($hasExplicitSharpen || $hasCustomPreset) {
            $parts[] = 'sha' . $sharpenAmount;
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
     * @param array<string, mixed> $attributes
     * 
     * @return string
     */
    protected function attrs(array $attributes): string
    {
        $attrs = [];
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }

            if ($value === true) {
                $attrs[] = $key;
                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', array_map(static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'), $value));
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
        $fs->label = $this->_('Settings');
        $inputfields->add($fs);

        /** @var InputfieldRadios $f */
        $f = $modules->get('InputfieldRadios');
        $f->name = 'driver';
        $f->label = $this->_('Image Processing Driver');

        $vipsAvailable = $this->isDriverHealthy(VipsDriver::class);
        $imagickAvailable = $this->isDriverHealthy(ImagickDriver::class);
        $gdAvailable = $this->isDriverHealthy(GdDriver::class);
        $activeDriverId = isset($this->intervention) ? $this->intervention->driver->id() : '';

        $availableLabel = $this->_('available');
        $unavailableLabel = $this->_('not available');
        $ffiEnable = strtolower(trim((string) ini_get('ffi.enable')));
        $isFfiPreloadRestricted = !$vipsAvailable && extension_loaded('ffi') && $ffiEnable === 'preload' && PHP_SAPI !== 'cli';

        $vipsStatusLabel = match (true) {
            $vipsAvailable => $availableLabel,
            $isFfiPreloadRestricted => $this->_('CLI only — see note below'),
            default => $unavailableLabel,
        };

        $f->addOptions([
            'auto' => $this->_('Auto Detect (Vips -> Imagick -> GD)') . ($activeDriverId !== '' ? sprintf(' (%s: %s)', $this->_('active'), $activeDriverId) : ''),
            'vips' => sprintf('%s (%s)', $this->_('Force Vips (libvips)'), $vipsStatusLabel),
            'imagick' => sprintf('%s (%s)', $this->_('Force Imagick'), $imagickAvailable ? $availableLabel : $unavailableLabel),
            'gd' => sprintf('%s (%s)', $this->_('Force GD'), $gdAvailable ? $availableLabel : $unavailableLabel),
        ]);
        if ($isFfiPreloadRestricted) {
            $iniPath = php_ini_loaded_file() ?: 'php.ini';
            $f->notes = sprintf(
                $this->_('libvips uses PHP FFI (`jcupitt/vips`). Because `ffi.enable=preload` is active in `%s`, PHP restricts FFI in Web/FPM requests while allowing it in CLI (`wire image:queue`). Set `ffi.enable=true` in `%s` and restart PHP-FPM to enable Vips in Web requests.'),
                $iniPath,
                $iniPath
            );
        }
        $f->value = $this->driver ?: 'auto';
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldCheckboxes $f */
        $f = $modules->get('InputfieldCheckboxes');
        $f->name = 'options';
        $f->label = $this->_('Options');
        $f->description = $this->_('Select additional options for image processing.');
        $f->addOptions([
            'delayed' => $this->_('Delays image processing until it is requested'),
            'lqip' => $this->_('Enable Low-Quality Image Placeholder (LQIP)'),
            'lazyload' => $this->_('Enable Fade-in Lazy Loading effect'),
            'inlineLazyload' => $this->_('Add Lazy Loading fade in inline style and script'),
            'upscale' => $this->_('Upscale images to fit requested dimensions'),
            'strip' => $this->_('Strip EXIF/Metadata from generated variations'),
            'progressive' => $this->_('Enable progressive JPEG / interlaced PNG encoding'),
            'avifAdd' => $this->_('Add AVIF format to picture tags'),
            'webpAdd' => $this->_('Add WebP format to picture tags')
        ]);
        $f->value = is_array($this->options) ? $this->options : [];
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldInteger $f */
        $f = $modules->get('InputfieldInteger');
        $f->name = 'quality';
        $f->label = $this->_('Default Quality');
        $f->description = $this->_('Default quality for generated images (0-100)');
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
        $f->label = $this->_('Default Output Format');
        $f->description = $this->_('Default output format for generated images.');
        $f->addOptions([
            'original' => $this->_('Original'),
            'webpOnly' => $this->_('WebP Only'),
            'avifOnly' => $this->_('AVIF Only')
        ]);
        $f->value = $this->outputFormat ?: $this->defaults['outputFormat'];
        $f->required = true;
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldTextarea $f */
        $f = $modules->get('InputfieldTextarea');
        $f->name = 'breakpoints';
        $f->label = $this->_('Breakpoints');
        $f->description = $this->_('Add breakpoints for responsive images. One per line.');
        $f->value = $this->breakpoints ?: $this->defaults['breakpoints'];
        $f->notes = $this->_('Breakpoints should be in the format `value=key|Label`. For set default breakpoint `value=+key|Label` format.');
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldTextarea $f */
        $f = $modules->get('InputfieldTextarea');
        $f->name = 'aspectRatios';
        $f->label = $this->_('Aspect Ratios');
        $f->description = $this->_('Add aspect ratios for responsive images. One per line.');
        $f->notes = $this->_('Aspect ratios should be in the format `width:height=key|Label`. For set default aspect ratio `width:height=+key|Label` format.');
        $f->value = $this->aspectRatios ?: $this->defaults['aspectRatios'];
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->name = 'columnWidths';
        $f->label = $this->_('Grid Columns');
        $f->description = $this->_('Comma-separated list of grid columns for responsive images.');
        $f->value = $this->columnWidths ?: $this->defaults['columnWidths'];
        $f->columnWidth = 50;
        $fs->add($f);

        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->name = 'factors';
        $f->label = $this->_('Responsive image resizing factors');
        $f->description = $this->_('Comma-separated list of responsive image resizing factors.');
        $f->value = $this->factors ?: $this->defaults['factors'];
        $f->columnWidth = 50;
        $fs->add($f);
    }
}
