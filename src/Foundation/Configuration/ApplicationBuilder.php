<?php

namespace Rdcstarr\Multisite\Foundation\Configuration;

class ApplicationBuilder extends \Illuminate\Foundation\Configuration\ApplicationBuilder
{
	public function withKernels(): static
	{
		$this->app->singleton(
			\Illuminate\Contracts\Http\Kernel::class,
			\Rdcstarr\Multisite\Foundation\Http\Kernel::class,
		);

		$this->app->singleton(
			\Illuminate\Contracts\Console\Kernel::class,
			\Rdcstarr\Multisite\Foundation\Console\Kernel::class,
		);

		return $this;
	}
}
