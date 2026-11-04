<?php

namespace App\Jobs;

use App\Models\StrategyEvaluation;
use App\Models\TradingStrategy;
use App\Services\Learning\PerformanceService;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StrategyEvaluationJob implements ShouldQueue
{
    use Queueable;
    public $tries = 1;
    public $timeout = 180;

    public function handle(PerformanceService $perf): void
    {
        foreach ($perf->byStrategy() as $code => $m) {
            $strategy = TradingStrategy::where('code', $code)->first();
            if (! $strategy) continue;
            StrategyEvaluation::create([
                'trading_strategy_id' => $strategy->id, 'version' => $strategy->version,
                'total_trades' => $m['total_trades'], 'win_rate' => $m['win_rate'],
                'profit_factor' => $m['profit_factor'], 'expectancy' => $m['expectancy'],
                'net_profit' => $m['net_profit'], 'max_drawdown' => $m['max_drawdown'],
                'verdict' => ($m['total_trades'] >= 10 && ($m['profit_factor'] ?? 0) >= 1.2) ? 'HEALTHY' : 'UNDER_REVIEW',
                'details' => $m,
            ]);
        }
    }
}
