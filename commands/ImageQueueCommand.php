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
		$config = wire('config');
		$files = wire('files');

		$baseDir = rtrim($config->paths->files, '/\\');
		$pageFilter = $input->getOption('page');
		if ($pageFilter !== null && $pageFilter !== '') {
			$page = is_numeric($pageFilter)
				? wire('pages')->get((int) $pageFilter)
				: wire('pages')->get((string) $pageFilter);

			if (!$page || !$page->id) {
				error("Page not found for filter: {$pageFilter}");
				return Command::FAILURE;
			}
			$baseDir = rtrim($page->filesManager()->path(), '/\\');
		}

		if (!is_dir($baseDir)) {
			info('No files directory found to scan.');
			return Command::SUCCESS;
		}

		$queueFiles = $this->findQueueFiles($baseDir);
		$total = count($queueFiles);

		if ($total === 0) {
			info('No pending .queue variations found.');
			return Command::SUCCESS;
		}

		if ((bool) $input->getOption('status')) {
			$rows = [];
			foreach (array_slice($queueFiles, 0, 50) as $qFile) {
				$data = json_decode((string) file_get_contents($qFile), true) ?: [];
				$rows[] = [
					str_replace($config->paths->files, '', substr($qFile, 0, -6)),
					($data['width'] ?? 0) . 'x' . ($data['height'] ?? 0),
					(string) ($data['source'] ?? ''),
				];
			}
			table(['Target Variation', 'Dimensions', 'Source'], $rows);
			note("Total pending queue files: {$total}");
			return Command::SUCCESS;
		}

		if ((bool) $input->getOption('clear')) {
			$removed = 0;
			foreach ($queueFiles as $qFile) {
				if ($files->unlink($qFile)) {
					$removed++;
				}
			}
			info("Cleared {$removed} pending .queue file(s).");
			return Command::SUCCESS;
		}

		$limit = max(1, (int) $input->getOption('limit'));
		$batch = array_slice($queueFiles, 0, $limit);
		$processed = 0;
		$failed = 0;

		foreach ($batch as $qFile) {
			try {
				$module->processQueueFile($qFile);
				$processed++;
			} catch (\Throwable $e) {
				$failed++;
				error('Failed: ' . basename($qFile) . ' — ' . $e->getMessage());
			}
		}

		$remaining = max(0, $total - count($batch));
		info("Processed {$processed} queued variation(s) ({$failed} failed, {$remaining} remaining).");

		return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * @param string $directory
	 * @return list<string>
	 */
	private function findQueueFiles(string $directory): array
	{
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
}
