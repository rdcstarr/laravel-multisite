<?php

namespace Rdcstarr\Multisite\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Prohibitable;
use Illuminate\Support\Facades\Process;
use Rdcstarr\Multisite\MultisiteManager;

class MultisiteSeedCommand extends Command
{
	use ConfirmableTrait, Prohibitable;

	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'multisite:seed
		{--force : Force the operation to run when in production}
		{--class= : The class name of the root seeder}
	';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Seed the database for all sites';

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
			$this->components->info('Run seeder for ' . $sites->count() . ' sites:');

			$sites->each(function ($site)
			{
				try
				{
					$this->components->task($site, fn() => match (true)
					{
						!MultisiteManager::isValid($site) => throw new Exception("Site invalid"),
						default => $this->runSeed($site)
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
	 * Run database seeding for a specific site.
	 *
	 * @param string $site
	 * @return void
	 */
	private function runSeed(string $site): void
	{
		$command = 'artisan db:seed --site=' . escapeshellarg($site) . ' --force';

		if ($this->option('class'))
		{
			$command .= ' --class=' . escapeshellarg($this->option('class'));
		}

		Process::run($command)->throw();
	}
}
