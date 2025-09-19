<?php

namespace Rdcstarr\Multisite\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Prohibitable;
use Illuminate\Support\Facades\Process;
use Rdcstarr\Multisite\MultisiteManager;

class MultisiteMigrateCommand extends Command
{
	use ConfirmableTrait, Prohibitable;

	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'multisite:migrate
		{--force : Force the operation to run when in production}
		{--seed : Indicates if the seed task should be re-run}
		{--seeder= : The class name of the root seeder}
	';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Migrate the database for all sites (optionally with seeding)';

	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function handle(): void
	{
		if ($this->isProhibited() || !$this->confirmToProceed())
		{
			return;
		}

		$sites = collect(MultisiteManager::all());

		if ($sites->isEmpty())
		{
			$this->components->info("No sites found.");
		}
		else
		{
			$action = $this->option('seed') || $this->option('seeder') ? 'migration with seeding' : 'migration';
			$this->components->info("Run {$action} for " . $sites->count() . ' sites:');

			$sites->each(function ($site)
			{
				try
				{
					$this->components->task($site, fn() => match (true)
					{
						!MultisiteManager::isValid($site) => throw new Exception("Site invalid"),
						default => $this->runMigrate($site)
					});
				}
				catch (Exception $e)
				{
					//
				}
			});

			$this->newLine();
		}
	}

	/**
	 * Run migration for a specific site
	 *
	 * @param string $site
	 * @return void
	 */
	private function runMigrate(string $site): void
	{
		$command = 'artisan migrate --site=' . escapeshellarg($site) . ' --force';

		if ($this->option('seed'))
		{
			$command .= ' --seed';
		}

		if ($this->option('seeder'))
		{
			$command .= ' --seeder=' . escapeshellarg($this->option('seeder'));
		}

		Process::run($command)->throw();
	}
}
