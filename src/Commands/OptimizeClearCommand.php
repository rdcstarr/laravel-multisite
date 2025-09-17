<?php

namespace Rdcstarr\Multisite\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Rdcstarr\Multisite\MultisiteManager;

class OptimizeClearCommand extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'optimize:clear {--site= : Specify the site to clear cache}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Remove the cached bootstrap files for all sites or a specific site';

	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function handle(): void
	{
		$sites = collect(MultisiteManager::all());
		$site  = $this->option('site');

		if ($sites->isEmpty())
		{
			$this->components->warn('No sites found.');
		}
		else
		{
			if (!empty($site))
			{
				if (!MultisiteManager::isValid($site))
				{
					MultisiteManager::throw("Site [{$site}] doesn't exist or doesn't have a valid env configuration.");
				}

				$this->components->info("Clear configuration cache for $site.");

				try
				{
					$this->components->task($site, fn() => match (true)
					{
						!MultisiteManager::isValid($site) => throw new Exception("Site invalid"),
						default => Artisan::call('config:cache', ['--site' => $site])
					});
				}
				catch (Exception $e)
				{
					//
				}
			}
			else
			{
				$this->components->info("Clear configuration cache for {$sites->count()} " . Str::plural('site', $sites->count()) . '.');

				$sites->each(function ($site)
				{
					try
					{
						$this->components->task($site, fn() => match (true)
						{
							!MultisiteManager::isValid($site) => throw new Exception("Site invalid"),
							default => Artisan::call('config:clear', ['--site' => $site])
						});
					}
					catch (Exception $e)
					{
						//
					}
				});
			}
		}

		$this->components->info("Clearing cached bootstrap files.");

		$tasks = collect($this->getOptimizeClearTasks());
		$tasks->each(function ($command, $description)
		{
			$this->components->task($description, fn() => $this->callSilently($command) == 0);
		});

		$this->newLine();
	}

	/**
	 * Get the commands that should be run to clear the "optimization" files.
	 *
	 * @return array
	 */
	protected function getOptimizeClearTasks(): array
	{
		return [
			'cache'    => 'cache:clear',
			'compiled' => 'clear-compiled',
			'events'   => 'event:clear',
			'routes'   => 'route:clear',
			'views'    => 'view:clear',
			...ServiceProvider::$optimizeClearCommands,
		];
	}
}
