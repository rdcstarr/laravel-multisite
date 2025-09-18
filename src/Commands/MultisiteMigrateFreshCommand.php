<?php

namespace Rdcstarr\Multisite\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Prohibitable;
use Illuminate\Support\Facades\Process;
use Rdcstarr\Multisite\MultisiteManager;

class MultisiteMigrateFreshCommand extends Command
{
	use ConfirmableTrait, Prohibitable;

	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'multisite:migrate-fresh
		{--force : Force the operation to run when in production}
		{--seed : Indicates if the seed task should be re-run}
		{--seeder : The class name of the root seeder}
	';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Migrate fresh the database for all sites';

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
			$this->components->info('Run migration for ' . $sites->count() . ' sites:');

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
	 * Run fresh migration for a specific site
	 *
	 * @param string $site
	 * @return void
	 */
	private function runMigrate(string $site): void
	{
		$command = 'artisan migrate:fresh --site=' . escapeshellarg($site) . ' --force';

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
