<?php

namespace App\Jobs;

use App\Models\PaperAccountSnapshot;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\TradingAccount;
use App\Services\Learning\PerformanceService;
use App\Services\Notifications\NotifyService;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DailyReportJob implements ShouldQueue
{
    use Queueable;
    public $tries = 2;

    public function handle(PerformanceService $perf, NotifyService $notify): void
    {
        $perf->snapshotDaily();
        foreach (TradingAccount::where('is_active', true)->get() as $account) {
            $unrealized = (float) PaperPosition::where('trading_account_id', $account->id)->where('status', 'OPEN')->sum('unrealized_pnl');
            PaperAccountSnapshot::updateOrCreate(
                ['trading_account_id' => $account->id, 'snapshot_date' => today()->toDateString()],
                ['balance' => $account->balance, 'equity' => $account->equity, 'unrealized_pnl' => $unrealized,
                 'realized_pnl' => $account->realized_pnl,
                 'open_positions' => PaperPosition::where('trading_account_id', $account->id)->where('status', 'OPEN')->count(),
                 'pending_orders' => \App\Models\PaperOrder::where('trading_account_id', $account->id)->where('status', 'PENDING')->count()]
            );
        }
        $m = $perf->compute();
        $notify->alert('daily_report', 'Daily trading report — Net ' . $m['net_profit'],
            "Trades: {$m['total_trades']} | Wins: {$m['wins']} | Win rate: {$m['win_rate']}% | Net: {$m['net_profit']} | PF: {$m['profit_factor']} | DD: {$m['max_drawdown']}", 60 * 20);
    }
}
