<?php

namespace Rdcstarr\Multisite;

use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Arr;
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
		'schedule:run',

		// Docker/Sail commands
		'sail:',

		// Multisite core commands
		'multisite-core:',
	];

	public static ?string $currentSite = null;
	public static ?string $sitesPath = null;
	public static ?string $basePath = null;
	public static ?string $publicPath = null;

	public static function bootstrap(): void
	{
		if (empty($_ENV['MULTISITE']) || !is_array($_ENV['MULTISITE']))
		{
			self::throw("MULTISITE environment variable is not defined.\nPlease define in your app bootstrapping file.");
		}

		if (empty($_ENV['MULTISITE']['production']) || !is_array($_ENV['MULTISITE']['production']))
		{
			self::throw("MULTISITE production environment variable is not defined.\nPlease define in your app bootstrapping file.");
		}

		if (empty($_ENV['MULTISITE']['local']) || !is_array($_ENV['MULTISITE']['local']))
		{
			self::throw("MULTISITE local environment variable is not defined.\nPlease define in your app bootstrapping file.");
		}

		$environment = $_ENV['APP_ENV'] === 'local' ? $_ENV['MULTISITE']['local'] : $_ENV['MULTISITE']['production'];

		foreach (['sites_path', 'base_path', 'public_path'] as $key)
		{
			if (empty($environment[$key]))
			{
				self::throw("MULTISITE.{$key} is not defined.\nPlease define in your app bootstrapping file.");
			}

			$property        = Str::camel($key);
			self::$$property = $environment[$key];
		}
	}

	public static function setCurrentSite(string $site): void
	{
		self::$currentSite = self::parseDomain($site);
	}

	/**
	 * Checks if a command should skip site validation
	 */
	public static function skipValidation(): bool
	{
		$command = $_SERVER['argv'][1] ?? 'artisan';

		if (empty($command) || $command === 'artisan')
		{
			return true;
		}

		$skipCommands = collect(static::SKIP_VALIDATION);

		if (!empty($_ENV["MULTISITE"]["SKIP_VALIDATION"]) && is_array($_ENV["MULTISITE"]["SKIP_VALIDATION"]))
		{
			$skipCommands = $skipCommands->merge($_ENV["MULTISITE"]["SKIP_VALIDATION"]);
		}

		return $skipCommands->contains(fn($skip) => Str::startsWith($command, $skip));
	}

	/**
	 * Extracts the --site argument from an array of arguments
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
	 * Parses the domain and returns the canonical form
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
	 * Returns the private path of a site
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
	 * Returns the public path of a site
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
	 * Sets a custom cached config path
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
	 * Checks if a site is valid (has .env)
	 */
	public static function isValid(?string $site = null): bool
	{
		$envPath = static::buildPath([self::getBasePath($site), '.env']);
		return self::filesystem()->exists($envPath);
	}

	/**
	 * Returns all available sites
	 */
	public static function all(): array
	{
		return collect(self::filesystem()->directories(static::$sitesPath))
			->map(fn($path) => basename($path))
			->reject(fn($dir) => Str::endsWith($dir, '.local'))
			->values()
			->all();
	}

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

	public static function isCli(): bool
	{
		return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
	}

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
	 * Gets the Filesystem instance
	 */
	protected static function filesystem(): Filesystem
	{
		return new Filesystem();
	}
}
