<?php

namespace App\Console\Commands;

use App\Jobs\FetchCandlesJob;
use App\Models\BotSession;
use App\Models\Setting;
use App\Models\TradingSymbol;

use Illuminate\Console\Command;

class SyncCandles extends Command
{
    protected $signature = 'bot:sync-candles';
    protected $description = 'Dispatch bounded candle sync jobs for enabled symbols/timeframes';

    public function handle(): int
    {
        if (! in_array(BotSession::current()->status, [BotSession::RUNNING, BotSession::PAUSED, BotSession::DEGRADED], true)) return 0;
        // Tiered dispatch: M1 (execution feed) every minute; coarser TFs every
        // 15 minutes. Syncing all 35 symbol/TF combos every minute outruns a
        // single worker and backlogs the queue unboundedly.
        $all = now()->minute % 15 === 0;
        $timeframes = array_map('trim', explode(',', (string) Setting::get('market.timeframes', 'M1,M15,H1,H4,D1')));
        foreach (TradingSymbol::where('is_enabled', true)->get() as $ts) {
            foreach ($timeframes as $tf) {
                if ($tf === '') continue;
                if ($tf !== 'M1' && ! $all) continue;
                FetchCandlesJob::dispatch($ts->symbol, $tf)->onQueue('market-data');
            }
        }
        return 0;
    }
}
