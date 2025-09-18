<?php

namespace Rdcstarr\Multisite\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class QueuePendingCommand extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'queue:pending {--failed : Show failed jobs instead of pending jobs}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Get number of pending or failed jobs for a specific site';

	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function handle(): void
	{
		echo json_encode([
			'pending' => Queue::size() ?? 0,
			'failed'  => DB::table('failed_jobs')->count() ?? 0
		]);

		return;
	}
}
