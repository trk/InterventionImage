<?php

declare(strict_types=1);

use Intervention\Image\Alignment;
use Intervention\Image\Direction;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\ImageHash\Strategies\AverageStrategy;
use Intervention\ImageHash\Strategies\BlockStrategy;
use Intervention\ImageHash\Strategies\DifferenceStrategy;
use Intervention\ImageHash\Strategies\PerceptualStrategy;
use ProcessWire\InterventionImage;
use function ProcessWire\wire;

/**
 * Helper to invoke protected methods on InterventionImage for unit testing.
 */
function invokeInterventionProtected(InterventionImage $module, string $method, array $args = []): mixed
{
	$ref = new ReflectionMethod($module, $method);
	return $ref->invokeArgs($module, $args);
}

test('calculate resolves explicit dimensions, aspect ratios, and breakpoint limits', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	$square = $module->calculate(600, null, 'square');
	expect($square['width'])->toBe(600)
		->and($square['height'])->toBe(600);

	$landscape = $module->calculate(1600, null, 'landscape', 'm');
	expect($landscape['maxWidth'])->toBe(960)
		->and($landscape['width'])->toBe(960)
		->and($landscape['height'])->toBe(540);
});

test('mapCropPosition maps ProcessWire compass strings to Intervention v4.4 Alignment', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	expect(invokeInterventionProtected($module, 'mapCropPosition', ['center']))->toBe(Alignment::CENTER)
		->and(invokeInterventionProtected($module, 'mapCropPosition', [true]))->toBe(Alignment::CENTER)
		->and(invokeInterventionProtected($module, 'mapCropPosition', ['north']))->toBe(Alignment::TOP)
		->and(invokeInterventionProtected($module, 'mapCropPosition', ['southeast']))->toBe(Alignment::BOTTOM_RIGHT)
		->and(invokeInterventionProtected($module, 'mapCropPosition', ['nw']))->toBe(Alignment::TOP_LEFT)
		->and(invokeInterventionProtected($module, 'mapCropPosition', [Alignment::LEFT]))->toBe(Alignment::LEFT);
});

test('normalizeColorize parses strings and arrays and clamps values to [-100, 100]', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	expect(invokeInterventionProtected($module, 'normalizeColorize', ['25, -15, 40']))->toBe([25, -15, 40])
		->and(invokeInterventionProtected($module, 'normalizeColorize', [[150, -140, 10]]))->toBe([100, -100, 10])
		->and(invokeInterventionProtected($module, 'normalizeColorize', [['red' => 10, 'green' => 20, 'blue' => -30]]))->toBe([10, 20, -30]);
});

test('createSuffix generates deterministic ProcessWire-compatible suffixes', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	$suffix = invokeInterventionProtected($module, 'createSuffix', [[
		'width' => 800,
		'height' => 600,
		'options' => [
			'suffix' => 'hero',
			'trim' => 10,
			'rotate' => 90,
			'flip' => Direction::HORIZONTAL,
			'contain' => true,
			'background' => '#ffffff',
			'grayscale' => true,
			'blur' => 5,
			'sharpen' => 15,
			'reduceColors' => 64,
			'quality' => 65,
		],
	]]);

	expect($suffix)->toBe('.800x600-hero-trm10-rot90-fliph-fit-bgffffff-gre-blu5-sha15-rc64-q65');

	$focusSuffix = invokeInterventionProtected($module, 'createSuffix', [[
		'width' => 400,
		'height' => 300,
		'options' => [
			'focus' => ['top' => 25, 'left' => 75, 'zoom' => 20],
		],
	]]);

	expect($focusSuffix)->toBe('.400x300-f25x75z20');

	$compassOverridesFocusSuffix = invokeInterventionProtected($module, 'createSuffix', [[
		'width' => 400,
		'height' => 300,
		'options' => [
			'cropping' => 'north',
			'focus' => ['top' => 25, 'left' => 75, 'zoom' => 20],
		],
	]]);
	expect($compassOverridesFocusSuffix)->toBe('.400x300-north');

	$arrayCropSuffix = invokeInterventionProtected($module, 'createSuffix', [[
		'width' => 400,
		'height' => 300,
		'options' => [
			'cropping' => [120, 80],
		],
	]]);
	expect($arrayCropSuffix)->toBe('.400x300-c120x80');

	$strongPresetSuffix = invokeInterventionProtected($module, 'createSuffix', [[
		'width' => 400,
		'height' => 300,
		'options' => [
			'sharpening' => 'strong',
		],
	]]);
	expect($strongPresetSuffix)->toBe('.400x300-sha40');

	$nonePresetSuffix = invokeInterventionProtected($module, 'createSuffix', [[
		'width' => 400,
		'height' => 300,
		'options' => [
			'sharpening' => 'none',
		],
	]]);
	expect($nonePresetSuffix)->toBe('.400x300-sha0');

	expect(invokeInterventionProtected($module, 'resolveSharpenAmount', [['sharpening' => 'soft']]))->toBe(10)
		->and(invokeInterventionProtected($module, 'resolveSharpenAmount', [['sharpening' => 'medium']]))->toBe(20)
		->and(invokeInterventionProtected($module, 'resolveSharpenAmount', [['sharpening' => 'strong', 'sharpen' => 35]]))->toBe(35)
		->and(invokeInterventionProtected($module, 'resolveSharpenAmount', [['sharpening' => 'none']]))->toBe(0);
});

test('isDriverHealthy validates Vips, Imagick, and GD drivers safely', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	$gdHealthy = invokeInterventionProtected($module, 'isDriverHealthy', [GdDriver::class]);
	$imagickHealthy = invokeInterventionProtected($module, 'isDriverHealthy', [ImagickDriver::class]);
	$vipsHealthy = invokeInterventionProtected($module, 'isDriverHealthy', [VipsDriver::class]);

	expect($gdHealthy)->toBeBool()
		->and($imagickHealthy)->toBeBool()
		->and($vipsHealthy)->toBeBool()
		->and($gdHealthy || $imagickHealthy || $vipsHealthy)->toBeTrue();

	$wrapper = new \ProcessWire\InputfieldWrapper();
	$module->getModuleConfigInputfields($wrapper);
	/** @var \ProcessWire\InputfieldRadios|null $driverField */
	$driverField = $wrapper->getChildByName('driver');
	expect($driverField)->not->toBeNull();
	$options = $driverField->getOptions();
	expect($options['vips'])->toContain($vipsHealthy ? '(available)' : '(not available)')
		->and($options['imagick'])->toContain($imagickHealthy ? '(available)' : '(not available)')
		->and($options['gd'])->toContain($gdHealthy ? '(available)' : '(not available)');
});

test('resolveHashStrategy maps shorthand names and StrategyInterface instances', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	$dhash = invokeInterventionProtected($module, 'resolveHashStrategy', ['dhash']);
	$phash = invokeInterventionProtected($module, 'resolveHashStrategy', ['phash']);
	$ahash = invokeInterventionProtected($module, 'resolveHashStrategy', ['ahash']);
	$bhash = invokeInterventionProtected($module, 'resolveHashStrategy', ['bhash']);
	$instance = invokeInterventionProtected($module, 'resolveHashStrategy', [new PerceptualStrategy()]);

	expect($dhash['key'])->toBe('dhash')
		->and($dhash['analyzer'])->toBeInstanceOf(DifferenceStrategy::class)
		->and($phash['key'])->toBe('phash')
		->and($phash['analyzer'])->toBeInstanceOf(PerceptualStrategy::class)
		->and($ahash['key'])->toBe('ahash')
		->and($ahash['analyzer'])->toBeInstanceOf(AverageStrategy::class)
		->and($bhash['key'])->toBe('bhash')
		->and($bhash['analyzer'])->toBeInstanceOf(BlockStrategy::class)
		->and($instance['key'])->toBe('phash')
		->and($instance['analyzer'])->toBeInstanceOf(PerceptualStrategy::class);

	expect(static fn(): mixed => invokeInterventionProtected($module, 'resolveHashStrategy', ['unknown_strategy']))
		->toThrow(\InvalidArgumentException::class);
});

test('resolveTargetHash computes perceptual hashes across strategies and compares similarity', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	$tmpFileA = sys_get_temp_dir() . '/pw_intervention_hash_a_' . uniqid() . '.png';
	$tmpFileB = sys_get_temp_dir() . '/pw_intervention_hash_b_' . uniqid() . '.jpg';

	try {
		$img = $module->manager()->createImage(64, 64)->fill('336699');
		$img->encodeUsingFormat(\Intervention\Image\Format::PNG)->save($tmpFileA);
		$img->scale(32, 32)->encodeUsingFormat(\Intervention\Image\Format::JPEG, quality: 85)->save($tmpFileB);

		foreach (['dhash', 'phash', 'ahash', 'bhash'] as $strategy) {
			/** @var \Intervention\ImageHash\Hash|null $hashA */
			$hashA = invokeInterventionProtected($module, 'resolveTargetHash', [$tmpFileA, $strategy]);
			/** @var \Intervention\ImageHash\Hash|null $hashB */
			$hashB = invokeInterventionProtected($module, 'resolveTargetHash', [$tmpFileB, $strategy]);

			$expectedHexLength = $strategy === 'bhash' ? 64 : 16;

			expect($hashA)->not->toBeNull()
				->and($hashB)->not->toBeNull()
				->and($hashA->toHex())->toMatch('/^[0-9a-f]{' . $expectedHexLength . '}$/i')
				->and($hashA->distance($hashB))->toBeLessThanOrEqual(5);

			/** @var \Intervention\ImageHash\Hash|null $fromHex */
			$fromHex = invokeInterventionProtected($module, 'resolveTargetHash', [$hashA->toHex(), $strategy]);
			expect($fromHex)->not->toBeNull()
				->and($fromHex->distance($hashA))->toBe(0);
		}

		$corruptFile = sys_get_temp_dir() . '/pw_intervention_corrupt_' . uniqid() . '.bin';
		file_put_contents($corruptFile, 'not-an-image-payload');
		try {
			expect(invokeInterventionProtected($module, 'resolveTargetHash', [$corruptFile, 'dhash']))->toBeNull();
		} finally {
			@unlink($corruptFile);
		}
	} finally {
		if (file_exists($tmpFileA)) {
			@unlink($tmpFileA);
		}
		if (file_exists($tmpFileB)) {
			@unlink($tmpFileB);
		}
	}
});

test('formatHexColor and sidecar JSON helpers read and write atomically', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	expect(invokeInterventionProtected($module, 'formatHexColor', ['#336699', true]))->toBe('#336699')
		->and(invokeInterventionProtected($module, 'formatHexColor', ['#336699', false]))->toBe('336699')
		->and(invokeInterventionProtected($module, 'formatHexColor', ['336699', true]))->toBe('#336699');

	$sidecarPath = sys_get_temp_dir() . '/pw_intervention_sidecar_' . uniqid() . '.json';
	try {
		expect(invokeInterventionProtected($module, 'readSidecarJson', [$sidecarPath]))->toBeNull();
		invokeInterventionProtected($module, 'writeSidecarJson', [$sidecarPath, ['dhash' => '8f9e9d8b0f0f1f07']]);
		expect(invokeInterventionProtected($module, 'readSidecarJson', [$sidecarPath]))
			->toBe(['dhash' => '8f9e9d8b0f0f1f07']);
	} finally {
		if (file_exists($sidecarPath)) {
			@unlink($sidecarPath);
		}
	}
});

test('inspectQueue and processQueue safely inspect and process queued variations', function (): void {
	/** @var InterventionImage $module */
	$module = wire('modules')->get('InterventionImage');

	$inspection = $module->inspectQueue(1, 10);
	expect($inspection)->toHaveKeys(['total', 'items'])
		->and($inspection['total'])->toBeInt()
		->and($inspection['items'])->toBeArray();

	$result = $module->processQueue(1, 10);
	expect($result)->toHaveKeys(['processed', 'failed', 'remaining', 'errors'])
		->and($result['processed'])->toBeInt()
		->and($result['failed'])->toBeInt()
		->and($result['remaining'])->toBeInt();
});
