<?php

namespace Rdcstarr\Multisite;

use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Illuminate\Filesystem\Filesystem;

class MultisiteManager
{
	use InteractsWithIO;

	/**
	 * List of commands that do not require site validation
	 *
	 * @var array
	 */
	protected const array SKIP_VALIDATION = [
		// General/help commands
		'help', 'list', 'completion', 'about', 'inspire',

		// Cache/optimization commands
		'cache:', 'view:', 'event:cache', 'event:clear',

		// Configuration/environment commands
		'env', 'config:publish', 'key:generate',

		// Development/serving commands
		'serve', 'pail',

		// Maintenance commands
		'down', 'up',

		// Routing commands
		'route:',

		// Code generation commands
		'make:',

		// Installation/publishing commands
		'install:', 'lang:publish', 'stub:publish', 'vendor:publish',

		// Asset management commands
		'storage:link', 'storage:unlink',

		// Package management commands
		'package:discover',

		// Debugging commands
		'debugbar:clear',

		// Scheduling commands (without DB)
		// 'schedule:run',

		// Docker/Sail commands
		'sail:',

		// Multisite commands
		'multisite', 'multisite:',

		// Core commands
		'core:',

		// Optimize commands
		'optimize',
	];

	/**
	 * The current active site identifier.
	 *
	 * @var string|null
	 */
	public static ?string $currentSite = null;

	/**
	 * Paths configuration
	 *
	 * @var string|null
	 */
	public static ?string $sitesPath = null;

	/**
	 * The base (private) path within each site directory.
	 *
	 * @var string|null
	 */
	public static ?string $basePath = null;

	/**
	 * The public path within each site directory.
	 *
	 * @var string|null
	 */
	public static ?string $publicPath = null;

	/**
	 * Initialize multisite environment configuration and paths.
	 *
	 * @return void
	 */
	public static function bootstrap(): void
	{
		if (empty($_SERVER['MULTISITE']) || !is_array($_SERVER['MULTISITE']))
		{
			self::throw("MULTISITE environment variable is not defined.");
		}

		$envKey = Env::get('APP_ENV') === 'local' ? 'local' : 'production';

		if (empty($_SERVER['MULTISITE'][$envKey]) || !is_array($_SERVER['MULTISITE'][$envKey]))
		{
			self::throw("MULTISITE.{$envKey} environment variable is not defined.");
		}

		$environment = $_SERVER['MULTISITE'][$envKey];

		foreach (['sites_path', 'base_path', 'public_path'] as $key)
		{
			if (empty($environment[$key]))
			{
				self::throw("MULTISITE.{$envKey}.{$key} is not defined.");
			}

			$property        = Str::camel($key);
			self::$$property = $environment[$key];
		}
	}

	/**
	 * Set the current active site after parsing the domain.
	 *
	 * @param string $site The incoming site domain or host string
	 * @return void
	 */
	public static function setCurrentSite(string $site): void
	{
		self::$currentSite = self::parseDomain($site);
	}

	/**
	 * Determine whether the given command should bypass site validation.
	 *
	 * @param string|null $command The command name or signature to check
	 * @return bool True if validation should be skipped, false otherwise
	 */
	public static function skipValidation(?string $command = null): bool
	{
		$command ??= $_SERVER['argv'][1] ?? 'artisan';

		if (empty($command) || $command === 'artisan')
		{
			return true;
		}

		$skipCommands = collect(static::SKIP_VALIDATION);

		if (!empty($_SERVER["MULTISITE"]["SKIP_VALIDATION"]) && is_array($_SERVER["MULTISITE"]["SKIP_VALIDATION"]))
		{
			$skipCommands = $skipCommands->merge($_SERVER["MULTISITE"]["SKIP_VALIDATION"]);
		}

		return $skipCommands->contains(fn($skip) => Str::startsWith($command, $skip));
	}

	/**
	 * Extract the --site argument from the CLI arguments array.
	 *
	 * @return string|null The site value if present, or null
	 */
	public static function extractSiteFromArgv(): ?string
	{
		$argv    = $_SERVER['argv'] ?? [];
		$siteArg = Arr::first($argv, fn($arg) => Str::startsWith($arg, '--site='));

		if (!$siteArg)
		{
			return null;
		}

		$site = trim(Str::after($siteArg, '--site='), '"\'');

		return !empty($site) ? $site : null;
	}

	/**
	 * Normalize and parse a domain into its canonical form.
	 *
	 * @param string $domain The domain or host string to parse
	 * @return string The parsed canonical domain
	 */
	public static function parseDomain(string $domain): string
	{
		return Str::of($domain)
			->lower()
			->replaceMatches('/^(https?:\/\/)?(www\.|api\.)?/', '')
			->replaceMatches('/:\d+$/', '')
			->toString();
	}

	/**
	 * Get the private (base) path for a given site.
	 *
	 * @param string|null $site Optional site identifier, defaults to current site
	 * @return string The site's base path
	 */
	public static function getBasePath(?string $site = null): string
	{
		$targetSite = $site ? static::parseDomain($site) : self::$currentSite;

		return static::buildPath([
			static::$sitesPath,
			$targetSite,
			static::$basePath,
		]);
	}

	/**
	 * Get the public path for a given site.
	 *
	 * @param string|null $site Optional site identifier, defaults to current site
	 * @return string The site's public path
	 */
	public static function getPublicPath(?string $site = null): string
	{
		$targetSite = $site ? static::parseDomain($site) : self::$currentSite;

		return static::buildPath([
			static::$sitesPath,
			$targetSite,
			static::$publicPath,
		]);
	}

	/**
	 * Build and return the path to the cached configuration file.
	 *
	 * @return string The cached config file path
	 */
	public static function getCachedConfigPath(): string
	{
		return self::buildPath([
			self::getBasePath(),
			'cache',
			'config.php',
		]);
	}

	/**
	 * Determine whether the specified site has a valid .env file.
	 *
	 * @param string|null $site Optional site identifier to validate
	 * @return bool True if the site's .env exists, false otherwise
	 */
	public static function isValid(?string $site = null): bool
	{
		$envPath = static::buildPath([self::getBasePath($site), '.env']);
		return self::filesystem()->exists($envPath);
	}

	/**
	 * Retrieve a list of all available sites.
	 *
	 * @return array<int,string> Array of site directory names
	 */
	public static function all(): array
	{
		return collect(self::filesystem()->directories(static::$sitesPath))
			->map(fn($path) => basename($path))
			->reject(fn($dir) => Str::endsWith($dir, '.local'))
			->values()
			->all();
	}

	/**
	 * Display an error and terminate execution.
	 *
	 * @param string|null $message Optional error message to display
	 * @return never
	 */
	public static function throw(?string $message = null): never
	{
		if (self::isCli())
		{
			self::displayCliError($message);
		}
		else
		{
			http_response_code(500);
		}

		exit(self::isCli() ? Command::SUCCESS : 1);
	}

	/**
	 * Render a styled CLI error box and output the message.
	 *
	 * @param string|null $message Message to display inside the error box
	 * @return void
	 */
	private static function displayCliError(?string $message): void
	{
		$output     = new OutputStyle(new ArgvInput(), new ConsoleOutput());
		$lineLength = mb_strlen($message) + 4;
		$emptyLine  = str_repeat(' ', $lineLength);

		$output->writeln(['',
			"<fg=white;bg=red>{$emptyLine}</fg=white;bg=red>",
			"<fg=white;bg=red>  {$message}  </fg=white;bg=red>",
			"<fg=white;bg=red>{$emptyLine}</fg=white;bg=red>",
			'',
		]);
	}

	/**
	 * Determine if the current runtime is the CLI sapi.
	 *
	 * @return bool True when running in CLI or phpdbg
	 */
	public static function isCli(): bool
	{
		return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
	}

	/**
	 * Build a filesystem path from a string or array of parts.
	 *
	 * @param string|array $path Path string or array of path segments
	 * @return string The assembled filesystem path
	 */
	private static function buildPath(string|array $path): string
	{
		if (is_string($path))
		{
			return Str::replace('/', DIRECTORY_SEPARATOR, $path);
		}

		$parts = collect($path)
			->filter(fn($part) => !empty($part))
			->map(fn($part) => trim(Str::replace('/', DIRECTORY_SEPARATOR, $part), DIRECTORY_SEPARATOR))
			->filter(fn($part) => !empty($part))
			->values()
			->all();

		$isAbsolute = is_string($path[0] ?? '') && Str::startsWith($path[0], '/');
		$result     = implode(DIRECTORY_SEPARATOR, $parts);

		return $isAbsolute ? DIRECTORY_SEPARATOR . $result : $result;
	}

	/**
	 * Get a new Filesystem instance.
	 *
	 * @return Filesystem
	 */
	protected static function filesystem(): Filesystem
	{
		return new Filesystem();
	}
}
