<?php

namespace Rdcstarr\Multisite\Foundation\Console;

use Rdcstarr\Multisite\Foundation\Console\Application as Artisan;
use Rdcstarr\Multisite\MultisiteManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Kernel extends \Illuminate\Foundation\Console\Kernel
{
	protected function bootstrappers(): array
	{
		return [
			\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
			\Rdcstarr\Multisite\Foundation\Bootstrap\MultisiteBootstrap::class,
			\Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
			\Illuminate\Foundation\Bootstrap\HandleExceptions::class,
			\Illuminate\Foundation\Bootstrap\RegisterFacades::class,
			\Illuminate\Foundation\Bootstrap\RegisterProviders::class,
			\Illuminate\Foundation\Bootstrap\BootProviders::class,
		];
	}

	/**
	 * Get the Artisan application instance.
	 */
	protected function getArtisan(): Application
	{
		if ($this->artisan === null)
		{
			$this->artisan = (new Artisan($this->app, $this->events, $this->app->version()))
				->resolveCommands($this->commands)
				->setContainerCommandLoader();

			if ($this->symfonyDispatcher instanceof EventDispatcher)
			{
				$this->artisan->setDispatcher($this->symfonyDispatcher);
			}
		}

		return $this->artisan;
	}

	/**
	 * Run an Artisan console command by name.
	 */
	public function call($command, array $parameters = [], $outputBuffer = null)
	{
		// Handle multisite site switch if needed
		if (isset($parameters['--site']) && !empty($parameters['--site']))
		{
			$originalArgv = $_SERVER['argv'] ?? [];

			// Set argv with site parameter
			$_SERVER['argv'] = ['artisan', '--site=' . $parameters['--site']];

			// Reset multisite state for fresh bootstrap
			MultisiteManager::$currentSite = null;

			collect(['multisite.current_site', 'multisite.bootstrapped', 'path.config.cache'])->each(function ($key)
			{
				$this->app->bound($key) && $this->app->forgetInstance($key);
			});
		}

		$this->app->bootstrapWith($this->bootstrappers());

		// Restore original argv if we modified it
		if (isset($originalArgv))
		{
			$_SERVER['argv'] = $originalArgv;
		}

		return $this->getArtisan()->call($command, $parameters, $outputBuffer);
	}
}
