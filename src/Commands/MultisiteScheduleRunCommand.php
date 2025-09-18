<?php

namespace Rdcstarr\Multisite\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rdcstarr\Multisite\MultisiteManager;

class MultisiteScheduleRunCommand extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'multisite:schedule-run';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Run the scheduler for all sites';


	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function handle(): void
	{
		$sites = collect(MultisiteManager::all());

		if ($sites->isEmpty())
		{
			$this->components->info("No sites found.");
		}
		else
		{
			$this->components->info('Run schedule for ' . $sites->count() . ' sites:');

			$sites->each(function ($site)
			{
				try
				{
					$this->components->task($site, fn() => match (true)
					{
						!MultisiteManager::isValid($site) => throw new Exception("Site invalid"),
						default => Process::run('artisan schedule:run --site=' . escapeshellarg($site))->throw()
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
}
