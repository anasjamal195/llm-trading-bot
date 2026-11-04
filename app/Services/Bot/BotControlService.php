<?php

namespace App\Services\Bot;

use App\Events\BotStatusChanged;
use App\Jobs\BotShutdownJob;
use App\Jobs\MarketInitializationJob;
use App\Models\AuditLog;
use App\Models\BotSession;
use App\Models\Setting;

use Illuminate\Support\Facades\DB;

/**
 * Bot lifecycle control with global lock. Idempotent start/stop.
 */
class BotControlService
{
    public function status(): BotSession
    {
        return BotSession::current();
    }

    public function start(?string $owner = 'admin'): array
    {
        return DB::transaction(function () use ($owner) {
            $s = BotSession::where('id', 1)->lockForUpdate()->first() ?? BotSession::current();
            if (in_array($s->status, [BotSession::RUNNING, BotSession::INITIALIZING], true)) {
                return ['ok' => false, 'status' => $s->status, 'message' => 'Bot already active'];
            }
            $s->update(['status' => BotSession::INITIALIZING, 'lock_owner' => $owner, 'lock_expires_at' => now()->addMinutes(10), 'started_at' => now(), 'status_message' => 'Initializing...']);
            BotStatusChanged::dispatch(BotSession::INITIALIZING);
            // Enqueue initialization; it flips to RUNNING on success.
            \App\Jobs\MarketInitializationJob::dispatch()->onQueue('market-data');
            AuditLog::log('bot', 'start');
            return ['ok' => true, 'status' => BotSession::INITIALIZING];
        });
    }

    public function pause(): array
    {
        $s = BotSession::current();
        if ($s->status !== BotSession::RUNNING) return ['ok' => false, 'status' => $s->status];
        $s->update(['status' => BotSession::PAUSED, 'status_message' => 'Paused: no new decisions; protection continues']);
        BotStatusChanged::dispatch(BotSession::PAUSED);
        AuditLog::log('bot', 'pause');
        return ['ok' => true, 'status' => BotSession::PAUSED];
    }

    public function resume(): array
    {
        $s = BotSession::current();
        if ($s->status !== BotSession::PAUSED) return ['ok' => false, 'status' => $s->status];
        $s->update(['status' => BotSession::RUNNING, 'status_message' => null]);
        BotStatusChanged::dispatch(BotSession::RUNNING);
        AuditLog::log('bot', 'resume');
        return ['ok' => true, 'status' => BotSession::RUNNING];
    }

    public function stop(string $policy = 'preserve'): array
    {
        return DB::transaction(function () use ($policy) {
            $s = BotSession::where('id', 1)->lockForUpdate()->first() ?? BotSession::current();
            $s->update(['status' => BotSession::STOPPING, 'status_message' => "Stopping ({$policy})..."]);
            BotStatusChanged::dispatch(BotSession::STOPPING);
            \App\Jobs\BotShutdownJob::dispatch($policy)->onQueue('trading');
            AuditLog::log('bot', 'stop', ['policy' => $policy]);
            return ['ok' => true, 'status' => BotSession::STOPPING];
        });
    }

    public function killSwitch(bool $on): void
    {
        Setting::put('risk.kill_switch', $on ? '1' : '', 'risk');
        AuditLog::log('bot', $on ? 'kill_switch_on' : 'kill_switch_off');
    }
}
