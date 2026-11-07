<?php

declare(strict_types=1);

use Intervention\Image\ImageManager;
use ProcessWire\InterventionImage;
use ProcessWire\Pageimage;
use ProcessWire\Pageimages;
use function ProcessWire\wire;

/**
 * Locate a real raster Pageimage in the ProcessWire installation for integration testing,
 * or provision a temporary synthetic Pageimage on the root page when no FieldtypeImage upload exists.
 */
function findTestablePageimage(): ?Pageimage
{
	static $syntheticImage = null;

	$pages = wire('pages')->find('limit=100, include=all');
	foreach ($pages as $p) {
		foreach ($p->fields as $f) {
			if ($f->type instanceof \ProcessWire\FieldtypeImage) {
				$val = $p->getUnformatted($f->name);
				$img = ($val instanceof Pageimages) ? $val->first() : ($val instanceof Pageimage ? $val : null);
				if ($img instanceof Pageimage && strtolower($img->ext) !== 'svg' && file_exists($img->filename) && filesize($img->filename) > 0) {
					return $img;
				}
			}
		}
	}

	if ($syntheticImage instanceof Pageimage && file_exists($syntheticImage->filename)) {
		return $syntheticImage;
	}

	$rootPage = wire('pages')->get(1);
	if (!$rootPage || !$rootPage->id) {
		return null;
	}

	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');
	$dir = $rootPage->filesManager()->path();
	if (!is_dir($dir)) {
		wire('files')->mkdir($dir, true);
	}

	$syntheticPath = $dir . 'pest_synthetic_fixture.png';
	if (!file_exists($syntheticPath)) {
		$canvas = $module->manager()->createImage(640, 480)->fill('2b5c8f');
		$canvas->encodeUsingFormat(\Intervention\Image\Format::PNG)->save($syntheticPath);
	}

	$pageimages = new Pageimages($rootPage);
	$syntheticImage = new Pageimage($pageimages, $syntheticPath);

	return $syntheticImage;
}

afterAll(function (): void {
	$rootPage = wire('pages')->get(1);
	if ($rootPage && $rootPage->id) {
		$dir = $rootPage->filesManager()->path();
		foreach (glob($dir . 'pest_synthetic_fixture*') ?: [] as $file) {
			if (is_file($file)) {
				@unlink($file);
			}
		}
	}
});

test('manager returns configured Intervention Image v4.4 ImageManager instance', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	expect($module)->toBeInstanceOf(InterventionImage::class)
		->and($module->manager())->toBeInstanceOf(ImageManager::class);
});

test('Pageimage size, crop, contain, and effects hooks generate valid image variations', function (): void {
	$image = findTestablePageimage();
	if (!$image) {
		$this->markTestSkipped('No raster Pageimage found in site to run integration test.');
	}

	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');
	$createdFiles = [];

	try {
		// 1. Standard size() with synchronous WebP rendering
		$sized = $image->size(320, 200, [
			'delayed' => false,
			'format' => 'webp',
			'quality' => 82,
			'suffix' => 'pesttest',
		]);
		$createdFiles[] = $sized->filename;

		expect($sized)->toBeInstanceOf(Pageimage::class)
			->and(file_exists($sized->filename))->toBeTrue()
			->and(filesize($sized->filename))->toBeGreaterThan(100);

		$decodedSized = $module->manager()->decodePath($sized->filename);
		expect($decodedSized->width())->toBe(320)
			->and($decodedSized->height())->toBe(200);

		// 2. Explicit coordinate crop()
		$cropped = $image->crop(10, 15, 240, 160, [
			'delayed' => false,
			'format' => 'webp',
			'suffix' => 'pesttest',
		]);
		$createdFiles[] = $cropped->filename;

		expect(file_exists($cropped->filename))->toBeTrue()
			->and($cropped->filename)->toContain('-c10x15');

		// 3. Contain / pad mode
		$contained = $image->contain(280, 280, [
			'background' => 'ffffff',
			'delayed' => false,
			'format' => 'webp',
			'suffix' => 'pesttest',
		]);
		$createdFiles[] = $contained->filename;

		expect(file_exists($contained->filename))->toBeTrue()
			->and($contained->filename)->toContain('-fit');

		$decodedContained = $module->manager()->decodePath($contained->filename);
		expect($decodedContained->width())->toBe(280)
			->and($decodedContained->height())->toBe(280);

		// 4. Trim + reduceColors + grayscale (verifies GD palette-to-truecolor guard on WebP)
		$filtered = $image->size(200, 150, [
			'delayed' => false,
			'format' => 'webp',
			'trim' => 5,
			'reduceColors' => 32,
			'grayscale' => true,
			'suffix' => 'pesttest',
		]);
		$createdFiles[] = $filtered->filename;

		expect(file_exists($filtered->filename))->toBeTrue()
			->and(filesize($filtered->filename))->toBeGreaterThan(100);

		// 5. Custom modifiers seam + preset/shorthand support
		$customModified = $image->size(220, 160, [
			'format' => 'webp',
			'suffix' => 'pestmod',
			'modifiers' => [
				static fn(\Intervention\Image\Interfaces\ImageInterface $img): \Intervention\Image\Interfaces\ImageInterface => $img->invert(),
			],
		]);
		$createdFiles[] = $customModified->filename;

		expect(file_exists($customModified->filename))->toBeTrue()
			->and($customModified->filename)->toContain('.220x160-pestmod-mod')
			->and(filesize($customModified->filename))->toBeGreaterThan(100);
	} finally {
		foreach ($createdFiles as $file) {
			if (is_string($file) && file_exists($file)) {
				@unlink($file);
			}
		}
	}
});

test('delayed queue file is created and processed by processQueueFile', function (): void {
	$image = findTestablePageimage();
	if (!$image) {
		$this->markTestSkipped('No raster Pageimage found in site to run integration test.');
	}

	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	$queued = $image->size(260, 180, [
		'delayed' => true,
		'format' => 'webp',
		'suffix' => 'pestqueue',
	]);
	$queueFile = $queued->filename . '.queue';

	try {
		expect(file_exists($queueFile))->toBeTrue();

		$encoded = $module->processQueueFile($queueFile);
		expect($encoded)->not->toBeNull()
			->and(file_exists($queueFile))->toBeFalse()
			->and(file_exists($queued->filename))->toBeTrue()
			->and(filesize($queued->filename))->toBeGreaterThan(100);
	} finally {
		if (file_exists($queueFile)) {
			@unlink($queueFile);
		}
		if (file_exists($queued->filename)) {
			@unlink($queued->filename);
		}
	}
});

test('lqip, dominantColor, palette, colorTheme, attrs, and render hooks return valid outputs', function (): void {
	$image = findTestablePageimage();
	if (!$image) {
		$this->markTestSkipped('No raster Pageimage found in site to run integration test.');
	}

	$lqip = $image->lqip();
	expect($lqip)->toStartWith('data:image/webp;base64,');

	$dominant = $image->dominantColor(true);
	expect($dominant)->toMatch('/^#[0-9a-f]{6}$/i');

	$dominantNoHash = $image->dominantColor(false);
	expect($dominantNoHash)->toMatch('/^[0-9a-f]{6}$/i');

	$palette = $image->palette(4, true);
	expect($palette)->toBeArray()
		->and(count($palette))->toBeGreaterThan(0)
		->and(count($palette))->toBeLessThanOrEqual(4);

	$theme = $image->colorTheme(true);
	expect($theme)->toBeArray()
		->and($theme)->toHaveKeys(['vibrant', 'muted', 'darkVibrant', 'darkMuted', 'lightVibrant', 'lightMuted']);

	$attrs = $image->attrs(400, 300, ['isFirst' => true, 'class' => 'test-img']);
	expect($attrs)->toBeArray()
		->and($attrs['width'])->toBe(400)
		->and($attrs['height'])->toBe(300)
		->and($attrs['fetchpriority'])->toBe('high')
		->and($attrs['class'])->toContain('test-img');

	$html = $image->render(400, 300, ['class' => 'test-render']);
	expect($html)->toContain('<img ')
		->and($html)->toContain('test-render');

	// Perceptual hash hooks (dhash + phash) & sidecar cache
	$dhash = $image->imageHash('dhash');
	$phash = $image->imageHash('phash');
	expect($dhash)->toMatch('/^[0-9a-f]{16}$/i')
		->and($phash)->toMatch('/^[0-9a-f]{16}$/i');

	$source = $image->getOriginal() ?: $image;
	$hashesFile = $source->pagefiles->path() . pathinfo($source->basename, PATHINFO_FILENAME) . '.hashes.json';
	expect(file_exists($hashesFile))->toBeTrue();

	expect($image->hashDistance($dhash, 'dhash'))->toBe(0)
		->and($image->hashDistance($image, 'dhash'))->toBe(0)
		->and($image->isSimilar($dhash, 5, 'dhash'))->toBeTrue();
});

test('clearVariations and Page::clearImageVariations support global and per-page cleanup', function (): void {
	$image = findTestablePageimage();
	if (!$image) {
		$this->markTestSkipped('No raster Pageimage found in site to run integration test.');
	}

	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');
	$page = $image->page;

	// Create a temporary variation and hash cache on this page
	$temp = $image->size(180, 120, [
		'delayed' => false,
		'format' => 'webp',
		'suffix' => 'pestclear',
	]);
	$image->imageHash('dhash');

	try {
		expect(file_exists($temp->filename))->toBeTrue();

		// Dry-run via Page::clearImageVariations() hook
		$pageStats = $page->clearImageVariations(['dryRun' => true]);
		expect($pageStats)->toBeArray()
			->and($pageStats)->toHaveKeys(['variations', 'lqip', 'colors', 'hashes', 'queue', 'total', 'bytes'])
			->and($pageStats['variations'])->toBeGreaterThan(0)
			->and($pageStats['hashes'])->toBeGreaterThan(0);

		// Dry-run globally via $module->clearVariations(null, ['dryRun' => true])
		$globalStats = $module->clearVariations(null, ['dryRun' => true]);
		expect($globalStats['total'])->toBeGreaterThanOrEqual($pageStats['total']);
	} finally {
		if (file_exists($temp->filename)) {
			@unlink($temp->filename);
		}
	}
});

