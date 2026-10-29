<?php

declare(strict_types=1);

use Intervention\Image\Alignment;
use Intervention\Image\Direction;
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
});
