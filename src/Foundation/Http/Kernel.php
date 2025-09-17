<?php

namespace Rdcstarr\Multisite\Foundation\Http;

class Kernel extends \Illuminate\Foundation\Http\Kernel
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
}
