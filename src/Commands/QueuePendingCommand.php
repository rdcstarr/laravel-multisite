<?php

namespace Rdcstarr\Multisite\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class QueuePendingCommand extends Command
{
	protected $signature = 'queue:pending {--failed : Show failed jobs instead of pending jobs}';

	protected $description = 'Get number of pending or failed jobs for a specific site';

	public function handle()
	{
		echo json_encode([
			'pending' => Queue::size() ?? 0,
			'failed'  => DB::table('failed_jobs')->count() ?? 0
		]);

		return self::SUCCESS;
	}
}
