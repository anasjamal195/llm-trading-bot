<?php

namespace App\Jobs;

use App\Models\BotSession;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\Setting;
use App\Services\MarketData\TickService;
use App\Services\PaperBroker\PaperBrokerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Real-time position handling on ~1s bridge ticks. Bounded: runs a short
 * polling loop (default 50s) then exits — the scheduler starts a new pass
 * every minute. A DB lease prevents overlapping loops. Only symbols with
 * open positions or pending orders are polled, so idle time costs nothing.
 * The M1-candle jobs remain as a fallback if ticks are unavailable.
 */
class MonitorTicksJob implements ShouldQueue
{
    use Queueable;
    public $tries = 1;
    public $timeout = 120;

    public function handle(TickService $ticks, PaperBrokerService $broker): void
    {
        if (! in_array(BotSession::current()->status, [BotSession::RUNNING, BotSession::PAUSED, BotSession::DEGRADED], true)) return;

        $leaseUntil = (int) Setting::get('tick_monitor.lease_until', '0');
        if ($leaseUntil > time()) return; // another loop is active
        $budget = (int) Setting::get('tick_monitor.seconds', 50);
        Setting::put('tick_monitor.lease_until', (string) (time() + $budget + 15), 'system');

        try {
            $end = time() + max(10, min(55, $budget));
            while (time() < $end) {
                $symbols = PaperPosition::where('status', 'OPEN')->distinct()->pluck('symbol')
                    ->merge(PaperOrder::where('status', 'PENDING')->distinct()->pluck('symbol'))
                    ->unique()->values();
                if ($symbols->isEmpty()) break;
                foreach ($symbols as $symbol) {
                    try {
                        $t = $ticks->tick($symbol);
                        $broker->processPendingOrderTicks($symbol, $t['bid'], $t['ask']);
                        $broker->monitorPositionTicks($symbol, $t['bid'], $t['ask']);
                    } catch (\Throwable) {
                        // One bad symbol must not stall the loop; candle jobs cover gaps.
                    }
                }
                sleep(1);
            }
        } finally {
            Setting::put('tick_monitor.lease_until', '0', 'system');
        }
    }
}
