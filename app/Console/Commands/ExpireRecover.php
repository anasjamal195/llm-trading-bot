<?php

namespace App\Console\Commands;

use App\Models\AgentTask;
use App\Models\PaperOrder;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireRecover extends Command
{
    protected $signature = 'bot:expire-recover';
    protected $description = 'Expire overdue orders/tasks and recover stale RUNNING leases';

    public function handle(): int
    {
        $expired = \App\Models\PaperOrder::where('status', 'PENDING')->where('expires_at', '<=', now())->update(['status' => 'EXPIRED']);
        $recovered = AgentTask::where('status', 'RUNNING')->where('lease_expires_at', '<', now())
            ->update(['status' => 'PENDING', 'retry_count' => DB::raw('retry_count + 1'), 'lease_owner' => null, 'lease_expires_at' => null]);
        AgentTask::where('status', 'PENDING')->where('retry_count', '>', 5)->update(['status' => 'EXPIRED']);
        $this->info("expired_orders={$expired} recovered_tasks={$recovered}");
        return 0;
    }
}
