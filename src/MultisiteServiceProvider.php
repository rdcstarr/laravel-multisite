<?php

namespace Rdcstarr\Multisite;

use Rdcstarr\Multisite\Commands\MultisiteListCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MultisiteServiceProvider extends PackageServiceProvider
{

	/**
	 * Configure the package using Laravel Package Tools.
	 *
	 * @param Package $package
	 * @return void
	 */
	public function configurePackage(Package $package): void
	{
		/*
		 * This class is a Package Service Provider
		 *
		 * More info: https://github.com/spatie/laravel-package-tools
		 */
		$package->name('multisite')
			// ->hasConfigFile()
			// ->hasCommands([
			// 	MultisiteListCommand::class,
			// ])
			;
	}
}
