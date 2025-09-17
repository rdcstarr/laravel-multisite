<?php

namespace Rdcstarr\Multisite\Commands;

use Illuminate\Console\Command;
use Rdcstarr\Multisite\MultisiteManager;

class MultisiteListCommand extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'multisite:list';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'List all available sites or show the current active site';

	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function handle(): void
	{
		$sites = collect(MultisiteManager::all());

		if ($sites->isEmpty())
		{
			$this->components->info("No sites found.");
		}
		else
		{
			$this->components->info('Found ' . $sites->count() . ' sites:');

			$sites->each(function ($site)
			{
				$status = MultisiteManager::isValid($site) ? '🟢' : '🔴';

				$this->line("  $status $site");
				$this->line("  URL: [https://$site]");
				$this->line("  <fg=gray>" . str_repeat("·", 100) . "</>");
			});
		}
	}
}
