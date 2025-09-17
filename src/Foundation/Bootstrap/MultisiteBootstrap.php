<?php

namespace Rdcstarr\Multisite\Foundation\Bootstrap;

use Illuminate\Support\Arr;
use Rdcstarr\Multisite\MultisiteManager;
use Dotenv\Dotenv;
use Illuminate\Contracts\Foundation\Application;

class MultisiteBootstrap
{
	/**
	 * Bootstrap the given application.
	 */
	public function bootstrap(Application $app): void
	{
		MultisiteManager::bootstrap();

		$site = $this->determineSite($app);

		if ($site)
		{
			MultisiteManager::setCurrentSite($site);
			$this->validateSite();
			$this->loadEnvironment();
			$this->bindCachedConfigPath($app);
		}
		// If no site is determined (skip validation), we don't validate, load env, or bind config
	}

	private function determineSite(Application $app): ?string
	{
		if ($app->runningInConsole())
		{
			return $this->handleConsoleMode();
		}

		return Arr::get($_SERVER, 'HTTP_HOST');
	}

	private function handleConsoleMode(): ?string
	{
		if (MultisiteManager::skipValidation())
		{
			return null;
		}

		$site = MultisiteManager::extractSiteFromArgv();

		if (empty($site))
		{
			MultisiteManager::throw("This command requires the --site argument.");
		}

		return $site;
	}

	private function validateSite(): void
	{
		if (!MultisiteManager::isValid())
		{
			MultisiteManager::throw(
				"Site [" . MultisiteManager::$currentSite . "] doesn't exist or doesn't have a valid env configuration."
			);
		}
	}

	private function loadEnvironment(): void
	{
		$dotenv = Dotenv::createMutable(MultisiteManager::getBasePath());
		$dotenv->load();
		$dotenv->required(['APP_URL', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);
	}

	private function bindCachedConfigPath(Application $app): void
	{
		$app->bind('path.config.cache', fn() => MultisiteManager::getCachedConfigPath());
	}
}
