<?php

namespace Rdcstarr\Multisite;

use Rdcstarr\Multisite\Commands\MultisiteScheduleRunCommand;
use Rdcstarr\Multisite\Commands\OptimizeCommand;
use Rdcstarr\Multisite\Commands\MultisiteListCommand;
use Rdcstarr\Multisite\Commands\OptimizeClearCommand;
use Rdcstarr\Multisite\Commands\MultisiteQueueCommand;
use Rdcstarr\Multisite\Commands\QueuePendingCommand;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\LaravelPackageTools\Package;

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
			->hasCommands([
				MultisiteListCommand::class,
				MultisiteQueueCommand::class,
				MultisiteScheduleRunCommand::class,
				OptimizeClearCommand::class,
				OptimizeCommand::class,
				QueuePendingCommand::class,
			]);
	}
}
