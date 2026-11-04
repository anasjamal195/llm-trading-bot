<?php

namespace App\Jobs;

use App\Models\TradingSymbol;
use App\Services\MarketData\MarketDataService;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MarketSnapshotJob implements ShouldQueue
{
    use Queueable;
    public $tries = 2;
    public $timeout = 120;

    public function handle(MarketDataService $md): void
    {
        foreach (TradingSymbol::where('is_enabled', true)->get() as $ts) {
            try {
                $snap = $md->snapshot($ts->symbol, 'H1');
                MarketSnapshotUpdated::dispatch([
                    'symbol' => $snap->symbol, 'price' => $snap->price,
                    'trend' => $snap->trend, 'regime' => $snap->regime, 'at' => now()->toIso8601String(),
                ]);
            } catch (\Throwable) {}
        }
    }
}
