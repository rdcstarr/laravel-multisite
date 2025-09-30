<?php

namespace Rdcstarr\Multisite\Foundation;

use Rdcstarr\Multisite\Foundation\Configuration\ApplicationBuilder;

class Application extends \Illuminate\Foundation\Application
{
	/**
	 * Begin configuring a new Laravel application instance.
	 *
	 * @param string|null $basePath
	 * @return \Rdcstarr\Multisite\Foundation\Configuration\ApplicationBuilder
	 */
	public static function configure(?string $basePath = null): ApplicationBuilder
	{
		$basePath = match (true)
		{
			is_string($basePath) => $basePath,
			default => static::inferBasePath(),
		};

		return (new ApplicationBuilder(new static($basePath)))
			->withKernels()
			->withEvents()
			->withCommands()
			->withProviders();
	}

	/**
	 * Get the path to the configuration cache file.
	 *
	 * @return string
	 */
	public function getCachedConfigPath(): string
	{
		if ($this->bound('path.config.cache'))
		{
			return $this->make('path.config.cache');
		}

		return parent::getCachedConfigPath();
	}

	/**
	 * Get the path to the routes cache file.
	 *
	 * @return string
	 */
	public function getCachedRoutesPath(): string
	{
		if ($this->bound('path.routes.cache'))
		{
			return $this->make('path.routes.cache');
		}

		return parent::getCachedRoutesPath();
	}
}
