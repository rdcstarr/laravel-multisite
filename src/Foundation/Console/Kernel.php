<?php

namespace Rdcstarr\Multisite\Foundation\Console;

use Rdcstarr\Multisite\Foundation\Console\Application as Artisan;
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
	protected function getArtisan()
	{
		if ($this->artisan === null)
		{
			$this->artisan = tap(
				new Artisan($this->app, $this->events, $this->app->version()),
				fn($artisan) => $artisan
					->resolveCommands($this->commands)
					->setContainerCommandLoader()
			);

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
		return $this->getArtisan()->call($command, $parameters, $outputBuffer);
	}

	/* protected function shouldDiscoverCommands()
	{
		if (get_class($this) === __CLASS__)
		{
			return true;
		}

		return parent::shouldDiscoverCommands();
	} */
}
