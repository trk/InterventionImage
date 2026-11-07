<?php

declare(strict_types=1);

namespace Totoglu\InterventionImage\Commands;

use ProcessWire\InterventionImage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function ProcessWire\wire;

final class ImageClearCommand extends Command
{
	protected function configure(): void
	{
		$this
			->setName('image:clear')
			->setDescription('Clear generated InterventionImage variations, LQIP placeholders (.lqip.webp), color caches (.colors.json), perceptual hashes (.hashes.json), and .queue files.')
			->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Restrict cleanup to a specific Page ID or path')
			->addOption('variations-only', null, InputOption::VALUE_NONE, 'Only remove generated image variation files (.WxH.*)')
			->addOption('lqip-only', null, InputOption::VALUE_NONE, 'Only remove .lqip.webp placeholder files')
			->addOption('colors-only', null, InputOption::VALUE_NONE, 'Only remove .colors.json metadata cache files')
			->addOption('hashes-only', null, InputOption::VALUE_NONE, 'Only remove .hashes.json perceptual hash cache files')
			->addOption('queue-only', null, InputOption::VALUE_NONE, 'Only remove .queue delayed variation files')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview files that would be deleted without removing them');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$modules = wire('modules');
		if (!$modules->isInstalled('InterventionImage')) {
			error('InterventionImage module is not installed.');
			return Command::FAILURE;
		}

		/** @var InterventionImage $module */
		$module = $modules->get('InterventionImage');

		$pageFilter = $input->getOption('page');
		$pageArg = ($pageFilter !== null && $pageFilter !== '') ? (string) $pageFilter : null;

		$variationsOnly = (bool) $input->getOption('variations-only');
		$lqipOnly = (bool) $input->getOption('lqip-only');
		$colorsOnly = (bool) $input->getOption('colors-only');
		$hashesOnly = (bool) $input->getOption('hashes-only');
		$queueOnly = (bool) $input->getOption('queue-only');
		$dryRun = (bool) $input->getOption('dry-run');

		$options = ['dryRun' => $dryRun];
		if ($variationsOnly || $lqipOnly || $colorsOnly || $hashesOnly || $queueOnly) {
			$options['variations'] = $variationsOnly;
			$options['lqip'] = $lqipOnly;
			$options['colors'] = $colorsOnly;
			$options['hashes'] = $hashesOnly;
			$options['queue'] = $queueOnly;
		}

		try {
			$counts = $module->clearVariations($pageArg, $options);
		} catch (\InvalidArgumentException $e) {
			error($e->getMessage());
			return Command::FAILURE;
		}

		$kb = number_format($counts['bytes'] / 1024, 1);

		table(
			['Artifact Type', $dryRun ? 'Matched Files (Dry Run)' : 'Deleted Files'],
			[
				['Image Variations (.WxH.*)', (string) $counts['variations']],
				['LQIP Placeholders (.lqip.webp)', (string) $counts['lqip']],
				['Color Metadata (.colors.json)', (string) $counts['colors']],
				['Perceptual Hashes (.hashes.json)', (string) $counts['hashes']],
				['Delayed Queue (.queue)', (string) $counts['queue']],
			]
		);

		if ($dryRun) {
			note("Dry run complete: {$counts['total']} file(s) ({$kb} KB) matched.");
		} else {
			info("Cleared {$counts['total']} file(s) ({$kb} KB freed).");
		}

		return Command::SUCCESS;
	}
}
