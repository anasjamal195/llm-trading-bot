<?php

namespace App\Console\Commands;

use App\Models\AgentTask;
use App\Models\BotSession;
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
        // Recover a bot stuck in INITIALIZING with an expired lock (e.g. worker
        // restarted mid-initialization and the reserved job was lost).
        $session = BotSession::current()->fresh();
        if ($session && $session->status === BotSession::INITIALIZING
            && $session->lock_expires_at && $session->lock_expires_at->isPast()) {
            $session->update(['status' => BotSession::ERROR, 'status_message' => 'Initialization timed out (stale lock recovered). Safe to Start again.', 'lock_owner' => null, 'lock_expires_at' => null]);
            \App\Events\BotStatusChanged::dispatch(BotSession::ERROR);
            \App\Models\AuditLog::log('bot', 'init_recovered');
            $this->info('recovered stale INITIALIZING session');
        }
        $this->info("expired_orders={$expired} recovered_tasks={$recovered}");
        return 0;
    }
}
