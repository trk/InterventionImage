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

final class ImageQueueCommand extends Command
{
	protected function configure(): void
	{
		$this
			->setName('image:queue')
			->setDescription('Process or inspect delayed InterventionImage (.queue) variations.')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of queued variations to process in one run', '50')
			->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Restrict queue processing to a specific Page ID or path')
			->addOption('status', 's', InputOption::VALUE_NONE, 'List pending .queue files without processing them')
			->addOption('clear', 'c', InputOption::VALUE_NONE, 'Remove pending .queue files without generating variations');
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
		$limit = max(1, (int) $input->getOption('limit'));

		try {
			$inspection = $module->inspectQueue($pageArg, $limit);
		} catch (\InvalidArgumentException $e) {
			error($e->getMessage());
			return Command::FAILURE;
		}

		if ($inspection['total'] === 0) {
			info('No pending .queue variations found.');
			return Command::SUCCESS;
		}

		if ((bool) $input->getOption('status')) {
			$rows = array_map(
				static fn(array $item): array => [
					$item['target'],
					$item['dimensions'],
					$item['source'],
				],
				$inspection['items']
			);
			table(['Target Variation', 'Dimensions', 'Source'], $rows);
			note("Total pending queue files: {$inspection['total']}");
			return Command::SUCCESS;
		}

		if ((bool) $input->getOption('clear')) {
			$counts = $module->clearVariations($pageArg, ['queue' => true]);
			info("Cleared {$counts['queue']} pending .queue file(s).");
			return Command::SUCCESS;
		}

		$result = $module->processQueue($pageArg, $limit);
		foreach ($result['errors'] as $errMessage) {
			error("Failed: {$errMessage}");
		}

		info("Processed {$result['processed']} queued variation(s) ({$result['failed']} failed, {$result['remaining']} remaining).");

		return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
	}
}
