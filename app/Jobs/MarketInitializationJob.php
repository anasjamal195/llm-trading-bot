<?php

namespace App\Jobs;

use App\Jobs\FetchCandlesJob;
use App\Models\AgentTask;
use App\Models\AuditLog;
use App\Models\BotSession;
use App\Models\Candle;
use App\Models\Setting;
use App\Models\SystemHealthLog;
use App\Models\TradingSymbol;
use App\Services\Notifications\NotifyService;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\MarketData\MarketDataService;
use App\Services\MT5\CandleProvider;


class MarketInitializationJob implements ShouldQueue
{
    use Queueable;
    public $tries = 1;
    public $timeout = 300;

    public function handle(MarketDataService $md, NotifyService $notify): void
    {
        $session = BotSession::current();
        if ($session->status !== BotSession::INITIALIZING) return;

        try {
            // 1. Bridge connectivity.
            $provider = app(\App\Services\MT5\CandleProvider::class);
            $health = $provider->health();
            if (! ($health['ok'] ?? false)) {
                throw new \RuntimeException('MT5 bridge not connected. ' . ($health['reason'] ?? json_encode($health['body'] ?? $health)));
            }
            // 2. Discover + reconcile symbols.
            try {
                $list = $provider->symbols('', 500);
                foreach ($list['symbols'] ?? [] as $sym) {
                    if (! is_string($sym) || strlen($sym) !== 6) continue;
                    TradingSymbol::firstOrCreate(['symbol' => strtoupper($sym)], [
                        'base_currency' => substr(strtoupper($sym), 0, 3),
                        'quote_currency' => substr(strtoupper($sym), 3, 3),
                        'is_enabled' => false, 'is_supported' => true,
                    ]);
                }
            } catch (\Throwable) { /* discovery best-effort */ }

            // 3-5. Backfill monitored symbols/timeframes.
            $timeframes = explode(',', (string) Setting::get('market.timeframes', 'M1,M15,H1,H4,D1'));
            foreach (TradingSymbol::where('is_enabled', true)->get() as $ts) {
                foreach ($timeframes as $tf) {
                    $tf = trim($tf);
                    if ($tf === '') continue;
                    try {
                        if (\App\Models\Candle::where('symbol', $ts->symbol)->where('timeframe', $tf)->count() < 50) {
                            $md->backfill($ts->symbol, $tf, now()->subDays(30), now());
                        } else {
                            $md->sync($ts->symbol, $tf);
                        }
                        FetchCandlesJob::dispatch($ts->symbol, $tf)->onQueue('market-data');
                    } catch (\Throwable $e) {
                        \App\Models\SystemHealthLog::create(['component' => 'init', 'status' => 'ERROR', 'message' => substr("{$ts->symbol} {$tf}: " . $e->getMessage(), 0, 500)]);
                    }
                }
            }

            // 6. Initial snapshots.
            foreach (TradingSymbol::where('is_enabled', true)->get() as $ts) {
                try { $md->snapshot($ts->symbol, 'H1'); } catch (\Throwable) {}
            }

            // 11-13. Kick off discovery + chief coordination.
            \App\Models\AgentTask::dispatchOnce([
                'task_type' => 'discover_pairs', 'agent_name' => 'market_discovery',
                'priority' => 9, 'status' => 'PENDING',
                'input_context' => ['trigger' => 'initialization'],
                'scheduled_at' => now(), 'dedupe_key' => 'init|discover|' . date('YmdHi'),
            ]);

            $session->update(['status' => BotSession::RUNNING, 'status_message' => null, 'state_snapshot' => ['initialized_at' => now()->toIso8601String()]]);
            \App\Events\BotStatusChanged::dispatch(BotSession::RUNNING);
            \App\Models\AuditLog::log('bot', 'initialized');
        } catch (\Throwable $e) {
            $session->update(['status' => BotSession::ERROR, 'status_message' => substr($e->getMessage(), 0, 500)]);
            \App\Events\BotStatusChanged::dispatch(BotSession::ERROR);
            $notify->alert('bot_startup_failed', 'Bot initialization failed', substr($e->getMessage(), 0, 1000), 30);
        }
    }
}
