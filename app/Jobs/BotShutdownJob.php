<?php

namespace App\Jobs;

use App\Models\AgentTask;
use App\Models\AuditLog;
use App\Models\BotSession;
use App\Models\PaperOrder;
use App\Models\PaperPosition;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BotShutdownJob implements ShouldQueue
{
    use Queueable;
    public $tries = 1;

    public function __construct(public string $policy = 'preserve') {}

    public function handle(): void
    {
        $session = BotSession::current();
        // Cancel stale agent tasks.
        \App\Models\AgentTask::whereIn('status', ['PENDING', 'RUNNING'])->update(['status' => 'CANCELLED']);
        if ($this->policy === 'close_all') {
            foreach (PaperPosition::where('status', 'OPEN')->get() as $p) {
                try { app(\App\Services\PaperBroker\PaperBrokerService::class)->closeEarly($p, 'SHUTDOWN_CLOSE'); } catch (\Throwable) {}
            }
            foreach (PaperOrder::where('status', 'PENDING')->get() as $o) {
                try { app(\App\Services\PaperBroker\PaperBrokerService::class)->cancelOrder($o); } catch (\Throwable) {}
            }
        }
        $session->update(['status' => BotSession::STOPPED, 'stopped_at' => now(), 'status_message' => null, 'lock_owner' => null, 'lock_expires_at' => null]);
        \App\Events\BotStatusChanged::dispatch(BotSession::STOPPED);
        \App\Models\AuditLog::log('bot', 'stopped', ['policy' => $this->policy]);
    }
}
