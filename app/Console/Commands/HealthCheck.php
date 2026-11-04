<?php

namespace App\Console\Commands;

use App\Events\BotStatusChanged;
use App\Events\SystemHealthUpdated;
use App\Models\BotSession;
use App\Models\Setting;
use App\Services\MarketData\MarketDataService;
use App\Services\Notifications\NotifyService;

use Illuminate\Console\Command;

class HealthCheck extends Command
{
    protected $signature = 'bot:health';
    protected $description = 'Market-data health monitor; degrades bot on stale feed';

    public function handle(MarketDataService $md, NotifyService $notify): int
    {
        $maxStale = (int) Setting::get('market.max_stale_seconds', 300);
        $health = $md->health($maxStale);
        $bad = array_filter($health, fn ($h) => in_array($h['status'], ['MISSING', 'STALE'], true));
        SystemHealthUpdated::dispatch(['components' => $health, 'at' => now()->toIso8601String()]);
        $session = BotSession::current();
        if ($bad !== [] && $session->status === BotSession::RUNNING) {
            $session->update(['status' => BotSession::DEGRADED, 'status_message' => 'Stale market data: ' . implode(',', array_column($bad, 'symbol'))]);
            BotStatusChanged::dispatch(BotSession::DEGRADED);
            $notify->alert('stale_feed', 'Market data stale', 'Stale: ' . implode(',', array_column($bad, 'symbol')), 60);
        } elseif ($bad === [] && $session->status === BotSession::DEGRADED) {
            $session->update(['status' => BotSession::RUNNING, 'status_message' => null]);
            BotStatusChanged::dispatch(BotSession::RUNNING);
        }
        return 0;
    }
}
