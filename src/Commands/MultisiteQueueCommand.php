<?php

namespace Rdcstarr\Multisite\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Rdcstarr\Multisite\MultisiteManager;

class MultisiteQueueCommand extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
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

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Dynamic load-balanced queue workers for multi-site system';

	private const int MONITOR_INTERVAL = 5;

	private array $workers = [];
	private array $siteStats = [];
	private int $totalWorkers;
	private bool $shouldRun = true;
	private bool $mute = false;

	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function handle(): void
	{
		$this->mute         = $this->option('mute');
		$this->totalWorkers = (int) $this->option('concurrency');

		$this->registerSignalHandlers();
		$this->displayConfiguration();
		$this->runDynamicLoadBalancer();
	}

	/**
	 * Register signal handlers for graceful shutdown.
	 *
	 * This method configures PCNTL async signals and installs handlers for
	 * SIGTERM and SIGINT to gracefully stop running worker processes.
	 *
	 * @return void
	 */
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

	/**
	 * Display the current configuration to the console.
	 *
	 * Shows a table with strategy, total sites, worker limits and
	 * rebalance interval so operators can verify settings at startup.
	 *
	 * @return void
	 */
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

	/**
	 * Main loop for the dynamic load balancer.
	 *
	 * Periodically rebalances worker allocation based on queued job counts
	 * and monitors worker processes, running until shutdown is requested.
	 *
	 * @return void
	 */
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

	/**
	 * Trigger a rebalance of workers across sites.
	 *
	 * Retrieves current queue sizes, computes a new allocation and
	 * adjusts workers accordingly. Optionally displays the new allocation.
	 *
	 * @return void
	 */
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

	/**
	 * Retrieve the list of active sites eligible for worker allocation.
	 *
	 * Filters the registered sites via the MultisiteManager to exclude
	 * invalid or inactive site entries.
	 *
	 * @return Collection<string> Collection of site identifiers
	 */
	private function getActiveSites(): Collection
	{
		return collect(MultisiteManager::all())->filter(fn(string $site) => MultisiteManager::isValid($site));
	}

	/**
	 * Get queue statistics for the provided sites.
	 *
	 * @param Collection<string> $sites Collection of site identifiers to inspect
	 * @return array<string,array{pending:int,priority:float}>
	 */
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

							$sizes[$site] = [
								'pending'  => $pending,
								'priority' => (float) $pending,
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
				catch (Exception $e)
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

		$activeSites = count(array_filter($sizes, fn($s) => $s['pending'] > 0));
		$this->output("Found {$activeSites} sites with pending jobs", 'info');

		return $sizes;
	}

	/**
	 * Return an empty stats structure for a site with no data.
	 *
	 * @return array{pending:int,priority:int}
	 */
	private function getEmptyStats(): array
	{
		return ['pending' => 0, 'priority' => 0];
	}

	/**
	 * Compute a priority score.
	 *
	 * @param int $pending Number of pending jobs
	 * @return float Computed priority score
	 */
	private function calculatePriority(int $pending): float
	{
		return (float) $pending;
	}

	/**
	 * Calculate how many workers should be allocated to each site.
	 *
	 * Uses site priority scores to proportionally allocate the total
	 * available workers while respecting min/max per-site constraints.
	 *
	 * @param array<string,array{priority:float}> $queueSizes Input statistics keyed by site
	 * @return array<string,int> Mapping of site => target worker count
	 */
	private function calculateWorkerAllocation(array $queueSizes): array
	{
		$allocation = [];
		$priority   = array_sum(array_column($queueSizes, 'priority'));

		if ($priority == 0)
		{
			// No jobs anywhere, distribute evenly among sites with recent activity
			$activeSites = array_keys(array_filter($queueSizes, fn($s) => $s['pending'] > 0));
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

			$ratio   = $stats['priority'] / $priority;
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

	/**
	 * Reconcile running workers with the target allocation.
	 *
	 * Stops workers for sites not present in the allocation, starts new
	 * workers where required and stops excess workers for sites with too many.
	 *
	 * @param array<string,int> $allocation Desired worker counts per site
	 * @return void
	 */
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

	/**
	 * Start a new worker process for a given site.
	 *
	 * Spawns a PHP process running the Laravel queue worker with environment
	 * variables identifying the site and worker id.
	 *
	 * @param string $site Site identifier for which to start the worker
	 * @param int $index Index used to compose a unique worker id
	 * @return void
	 */
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

	/**
	 * Stop a single worker process by its identifier.
	 *
	 * Signals the process to terminate and removes it from the tracked list.
	 *
	 * @param string $workerId Identifier of the worker to stop
	 * @return void
	 */
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

	/**
	 * Count the number of workers currently running for a site.
	 *
	 * @param string $site Site identifier
	 * @return int Number of workers for the site
	 */
	private function getWorkerCountForSite(string $site): int
	{
		return count(array_filter(
			array_keys($this->workers),
			fn($workerId) => str_starts_with($workerId, "$site-")
		));
	}

	/**
	 * Stop a given number of excess workers for a specific site.
	 *
	 * Stops the most recently started workers for the site until the
	 * requested count has been removed.
	 *
	 * @param string $site Site identifier
	 * @param int $count Number of workers to stop
	 * @return void
	 */
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

	/**
	 * Monitor worker processes and clean up any that have exited.
	 *
	 * Logs unexpected exits and removes dead processes from the internal
	 * registry so they can be restarted during the next rebalance.
	 *
	 * @return void
	 */
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

	/**
	 * Display the current allocation to the console.
	 *
	 * Prints a human-readable list of sites and their allocated worker counts
	 * and shows the total active workers.
	 *
	 * @param array<string,int> $allocation Allocation mapping site => worker count
	 * @return void
	 */
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

	/**
	 * Stop all running workers, attempting graceful shutdown first.
	 *
	 * Signals each worker to terminate and waits for a short timeout before
	 * force-killing any remaining processes.
	 *
	 * @return void
	 */
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

	/**
	 * Console output helper that respects the mute option.
	 *
	 * Routes messages to the appropriate console method based on the
	 * specified message type (info, warn, error, line).
	 *
	 * @param string $message Message text to display
	 * @param string $type Output type (info|warn|error|line)
	 * @return void
	 */
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
