<?php

namespace Rdcstarr\Multisite\Commands;

use Artisan;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
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
						default => Artisan::call('schedule:run', ['--site' => $site])
					});
				}
				catch (Exception $e)
				{
					//
				}
			});
		}
	}
}
