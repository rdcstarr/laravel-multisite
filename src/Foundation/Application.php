<?php

namespace Rdcstarr\Multisite\Foundation;

use Rdcstarr\Multisite\Foundation\Configuration\ApplicationBuilder;

class Application extends \Illuminate\Foundation\Application
{
	/**
	 * Begin configuring a new Laravel application instance.
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

	public function getCachedConfigPath(): string
	{
		if ($this->bound('path.config.cache'))
		{
			return $this->make('path.config.cache');
		}

		return parent::getCachedConfigPath();
	}
}
