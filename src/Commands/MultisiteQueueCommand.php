<?php

namespace Rdcstarr\Multisite\Commands;

use App\Services\SiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Rdcstarr\Multisite\MultisiteManager;

class MultisiteQueueCommand extends Command
{
	protected $signature = 'multisite:queue
        {--concurrency=10 : Total number of concurrent workers}
        {--min-per-site=1 : Minimum workers per active site}
        {--max-per-site=5 : Maximum workers per active site}
        {--check-interval=30 : Seconds between load checks}
        {--sleep=3 : Number of seconds to sleep when no jobs}
        {--tries=3 : Number of attempts per job}
        {--timeout=60 : Job timeout in seconds}
        {--memory=128 : Memory limit per worker in MB}
        {--mute : Run without output}
    ';

	protected $description = 'Dynamic load-balanced queue workers for multi-site system';

	private const int MONITOR_INTERVAL = 5;

	private array $workers = [];
	private array $siteStats = [];
	private int $totalWorkers;
	private bool $shouldRun = true;
	private bool $mute = false;

	public function handle(): int
	{
		$this->mute         = $this->option('mute');
		$this->totalWorkers = (int) $this->option('concurrency');

		$this->registerSignalHandlers();
		$this->displayConfiguration();
		$this->runDynamicLoadBalancer();

		return self::SUCCESS;
	}

	private function registerSignalHandlers(): void
	{
		if (!extension_loaded('pcntl'))
		{
			$this->output('PCNTL extension not available', 'warn');
			return;
		}

		pcntl_async_signals(true);

		$shutdownHandler = function (int $signal): void
		{
			$this->newLine();
			$this->output('🛑 Shutting down gracefully...', 'info');
			$this->shouldRun = false;
			$this->stopAllWorkers();
			exit(0);
		};

		pcntl_signal(SIGTERM, $shutdownHandler);
		pcntl_signal(SIGINT, $shutdownHandler);
	}

	private function displayConfiguration(): void
	{
		$sites = $this->getActiveSites();

		$info = [
			['Strategy', 'Dynamic Load Balancer'],
			['Total Sites', $sites->count()],
			['Total Workers', $this->totalWorkers],
			['Min Workers/Site', $this->option('min-per-site')],
			['Max Workers/Site', $this->option('max-per-site')],
			['Rebalance Interval', $this->option('check-interval') . 's'],
		];

		$this->table(['Config', 'Value'], $info);
		$this->newLine();
	}

	private function runDynamicLoadBalancer(): void
	{
		$this->output('🚀 Starting dynamic load balancer...', 'info');

		$lastRebalance = 0;

		while ($this->shouldRun)
		{
			// Rebalance workers based on queue sizes
			if (time() - $lastRebalance >= $this->option('check-interval'))
			{
				$this->rebalanceWorkers();
				$lastRebalance = time();
			}

			// Monitor and restart failed workers
			$this->monitorWorkers();

			sleep(self::MONITOR_INTERVAL);
		}
	}

	private function rebalanceWorkers(): void
	{
		$sites      = $this->getActiveSites();
		$queueSizes = $this->getQueueSizes($sites);
		$allocation = $this->calculateWorkerAllocation($queueSizes);

		$this->output('📊 Rebalancing workers...', 'info');
		$this->adjustWorkers($allocation);

		if (!$this->mute)
		{
			$this->displayAllocation($allocation);
		}
	}

	private function getActiveSites(): Collection
	{
		return collect(MultisiteManager::all())->filter(fn(string $site) => MultisiteManager::isValid($site));
	}

	private function getQueueSizes(Collection $sites): array
	{
		$sizes     = [];
		$batchSize = 50; // Process max 50 sites at once to avoid resource issues

		$this->output("📊 Checking queue sizes for {$sites->count()} sites...", 'info');

		// Process sites in batches
		foreach ($sites->chunk($batchSize) as $batch)
		{
			$processes = [];

			// Start parallel processes for this batch
			foreach ($batch as $site)
			{
				$command = sprintf(
					'artisan queue:pending --site=%s',
					escapeshellarg($site)
				);

				$processes[$site] = Process::run($command);
			}

			// Collect results for this batch
			foreach ($processes as $site => $process)
			{
				try
				{
					if ($process->successful())
					{
						$output = trim($process->output());
						$stats  = json_decode($output, true);

						if ($stats && is_array($stats))
						{
							$pending = $stats['pending'] ?? 0;
							$failed  = $stats['failed'] ?? 0;

							$sizes[$site] = [
								'pending'  => $pending,
								'failed'   => $failed,
								'total'    => $pending + $failed,
								'priority' => $this->calculatePriority($pending, $failed),
							];
						}
						else
						{
							$sizes[$site] = $this->getEmptyStats();
						}
					}
					else
					{
						$sizes[$site] = $this->getEmptyStats();
					}
				}
				catch (\Exception $e)
				{
					$sizes[$site] = $this->getEmptyStats();
				}
			}

			// Small delay between batches to avoid overwhelming the system
			if ($sites->count() > $batchSize)
			{
				sleep(1);
			}
		}

		$activeSites = count(array_filter($sizes, fn($s) => $s['total'] > 0));
		$this->output("Found {$activeSites} sites with pending jobs", 'info');

		return $sizes;
	}

	private function getEmptyStats(): array
	{
		return ['pending' => 0, 'failed' => 0, 'total' => 0, 'priority' => 0];
	}

	private function calculatePriority(int $pending, int $failed): float
	{
		// Priority algorithm: pending jobs + failed jobs with higher weight
		return $pending + $failed * 2;
	}

	private function calculateWorkerAllocation(array $queueSizes): array
	{
		$allocation    = [];
		$totalPriority = array_sum(array_column($queueSizes, 'priority'));

		if ($totalPriority == 0)
		{
			// No jobs anywhere, distribute evenly among sites with recent activity
			$activeSites = array_keys(array_filter($queueSizes, fn($s) => $s['total'] > 0));
			if (empty($activeSites))
			{
				return [];
			}

			$workersPerSite = max(1, intval($this->totalWorkers / count($activeSites)));
			foreach ($activeSites as $site)
			{
				$allocation[$site] = min($workersPerSite, $this->option('max-per-site'));
			}
			return $allocation;
		}

		// Allocate based on priority
		$remainingWorkers = $this->totalWorkers;

		foreach ($queueSizes as $site => $stats)
		{
			if ($stats['priority'] == 0)
				continue;

			$ratio   = $stats['priority'] / $totalPriority;
			$workers = max(
				$this->option('min-per-site'),
				min(
					$this->option('max-per-site'),
					intval($this->totalWorkers * $ratio)
				)
			);

			$allocation[$site] = $workers;
			$remainingWorkers -= $workers;
		}

		// Distribute remaining workers to high-priority sites
		while ($remainingWorkers > 0)
		{
			$assigned = false;
			foreach ($allocation as $site => &$workers)
			{
				if ($workers < $this->option('max-per-site') && $queueSizes[$site]['priority'] > 0)
				{
					$workers++;
					$remainingWorkers--;
					$assigned = true;
					if ($remainingWorkers == 0)
						break;
				}
			}
			if (!$assigned)
				break;
		}

		return $allocation;
	}

	private function adjustWorkers(array $allocation): void
	{
		// Stop workers for sites not in allocation
		foreach ($this->workers as $workerId => $process)
		{
			[$site] = explode('-', $workerId, 2);
			if (!isset($allocation[$site]))
			{
				$this->stopWorker($workerId);
			}
		}

		// Adjust worker count for each site
		foreach ($allocation as $site => $targetCount)
		{
			$currentCount = $this->getWorkerCountForSite($site);

			if ($currentCount < $targetCount)
			{
				// Start more workers
				for ($i = $currentCount; $i < $targetCount; $i++)
				{
					$this->startWorker($site, $i);
				}
			}
			elseif ($currentCount > $targetCount)
			{
				// Stop excess workers
				$workersToStop = $currentCount - $targetCount;
				$this->stopExcessWorkersForSite($site, $workersToStop);
			}
		}
	}

	private function startWorker(string $site, int $index): void
	{
		$workerId = "{$site}-{$index}";

		$command = sprintf(
			"/usr/bin/php -d memory_limit=%dM artisan queue:work --site=%s --sleep=%d --tries=%d --timeout=%d --verbose",
			$this->option('memory'),
			escapeshellarg($site),
			$this->option('sleep'),
			$this->option('tries'),
			$this->option('timeout')
		);

		$this->workers[$workerId] = Process::env([
			'QUEUE_SITE' => $site,
			'WORKER_ID'  => $workerId,
		])->start($command);

		$this->output("  ✅ Started worker {$workerId}", 'line');
	}

	private function stopWorker(string $workerId): void
	{
		if (!isset($this->workers[$workerId]))
			return;

		$process = $this->workers[$workerId];
		if ($process->running())
		{
			$process->signal(SIGTERM);
		}

		unset($this->workers[$workerId]);
		$this->output("  ❌ Stopped worker {$workerId}", 'line');
	}

	private function getWorkerCountForSite(string $site): int
	{
		return count(array_filter(
			array_keys($this->workers),
			fn($workerId) => str_starts_with($workerId, "$site-")
		));
	}

	private function stopExcessWorkersForSite(string $site, int $count): void
	{
		$siteWorkers = array_filter(
			array_keys($this->workers),
			fn($workerId) => str_starts_with($workerId, "$site-")
		);

		$workersToStop = array_slice($siteWorkers, -$count);
		foreach ($workersToStop as $workerId)
		{
			$this->stopWorker($workerId);
		}
	}

	private function monitorWorkers(): void
	{
		foreach ($this->workers as $workerId => $process)
		{
			if (!$process->running())
			{
				$this->output("⚠️ Worker {$workerId} died unexpectedly", 'warn');
				unset($this->workers[$workerId]);
			}
		}
	}

	private function displayAllocation(array $allocation): void
	{
		$this->line('📋 Current allocation:');
		foreach ($allocation as $site => $workers)
		{
			$this->line("  └─ {$site}: {$workers} workers");
		}
		$this->line("Total active workers: " . array_sum($allocation));
		$this->newLine();
	}

	private function stopAllWorkers(): void
	{
		foreach ($this->workers as $workerId => $process)
		{
			if ($process->running())
			{
				$process->signal(SIGTERM);
			}
		}

		// Wait for graceful shutdown
		$timeout = 10;
		$start   = time();

		while (time() - $start < $timeout && !empty($this->workers))
		{
			foreach ($this->workers as $workerId => $process)
			{
				if (!$process->running())
				{
					unset($this->workers[$workerId]);
				}
			}
			sleep(1);
		}

		// Force kill remaining
		foreach ($this->workers as $process)
		{
			if ($process->running())
			{
				$process->signal(SIGKILL);
			}
		}

		$this->output('✅ All workers stopped', 'info');
	}

	private function output(string $message, string $type = 'line'): void
	{
		if ($this->mute)
			return;

		match ($type)
		{
			'info' => $this->info($message),
			'warn' => $this->warn($message),
			'error' => $this->error($message),
			default => $this->line($message),
		};
	}
}
