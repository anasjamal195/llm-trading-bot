<?php

namespace App\Jobs;

use App\Models\Candle;
use App\Models\TradingSymbol;
use App\Services\PaperBroker\PaperBrokerService;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPendingOrdersJob implements ShouldQueue
{
    use Queueable;
    public $tries = 2;
    public $timeout = 120;

    public function handle(PaperBrokerService $broker): void
    {
        $broker->expireDueOrders();
        foreach (TradingSymbol::where('is_enabled', true)->pluck('symbol') as $symbol) {
            $latest = Candle::where('symbol', $symbol)->where('timeframe', 'M1')->orderBy('candle_time', 'desc')->first();
            if ($latest) $broker->processPendingOrders($symbol, $latest);
        }
    }
}
