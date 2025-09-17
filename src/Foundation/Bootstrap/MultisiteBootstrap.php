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
	 *
	 * Bootstraps multisite functionality for the given application instance.
	 * It initializes the MultisiteManager, determines the active site (from
	 * the HTTP host or the console --site argument), validates the site,
	 * loads the site's environment file if present, and binds site-specific
	 * paths and instances into the application container.
	 *
	 * @param Application $app The application instance being bootstrapped.
	 * @return void
	 */
	public function bootstrap(Application $app): void
	{
		MultisiteManager::bootstrap();

		$site = $app->runningInConsole()
			? $this->getConsoleSite()
			: Arr::get($_SERVER, 'HTTP_HOST');

		if (!$site)
		{
			return;
		}

		// Skip if same site already bootstrapped
		if ($app->bound('multisite.current_site') && $app->get('multisite.current_site') === $site)
		{
			return;
		}

		// Validate and bootstrap site
		MultisiteManager::setCurrentSite($site);

		if (!MultisiteManager::isValid())
		{
			MultisiteManager::throw("Site [{$site}] doesn't exist or doesn't have a valid env configuration.");
		}

		// Load environment
		$envFile = MultisiteManager::getBasePath() . DIRECTORY_SEPARATOR . '.env';
		if (file_exists($envFile))
		{
			Dotenv::createMutable(MultisiteManager::getBasePath())->safeLoad();
		}

		// Bind paths and mark as bootstrapped
		$app->bind('path.config.cache', fn() => MultisiteManager::getCachedConfigPath());
		$app->instance('multisite.current_site', $site);
	}

	/**
	 * Get the site name from console arguments when running in CLI.
	 *
	 * Extracts the --site argument from argv and enforces validation unless
	 * validation is explicitly skipped. Throws an exception when the required
	 * argument is not provided.
	 *
	 * @return string|null The site identifier, or null when validation is skipped
	 */
	private function getConsoleSite(): ?string
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
}
