<?php

namespace Rdcstarr\Multisite\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Rdcstarr\Multisite\MultisiteManager;

class OptimizeCommand extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'optimize {--site= : Specify the site to optimize}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Cache framework bootstrap, configuration, and metadata for all sites or a specific site';

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

				$this->components->info("Caching configuration for $site.");

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
				$this->components->info("Caching configuration for {$sites->count()} " . Str::plural('site', $sites->count()) . '.');

				$sites->each(function ($site)
				{
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
				});
			}
		}

		$this->components->info("Caching framework bootstrap and metadata.");

		$tasks = collect($this->getOptimizeTasks());
		$tasks->each(function ($command, $description)
		{
			$this->components->task($description, fn() => $this->callSilently($command) == 0);
		});

		$this->newLine();
	}

	/**
	 * Get the commands that should be run to optimize the framework.
	 *
	 * @return array
	 */
	protected function getOptimizeTasks()
	{
		return [
			'events' => 'event:cache',
			'routes' => 'route:cache',
			'views'  => 'view:cache',
			...ServiceProvider::$optimizeCommands,
		];
	}
}
