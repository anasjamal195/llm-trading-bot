<?php

namespace App\Services\Learning;

use App\Events\PerformanceMetricsUpdated;
use App\Models\PaperPosition;
use App\Models\PerformanceMetric;


/**
 * Deterministic performance analytics from stored trades.
 */
class PerformanceService
{
    public function compute(?string $symbol = null, ?string $strategy = null): array
    {
        $q = PaperPosition::where('status', 'CLOSED');
        if ($symbol) $q->where('symbol', $symbol);
        if ($strategy) $q->where('strategy_code', $strategy);
        $trades = $q->orderBy('closed_at')->get();

        $total = $trades->count();
        $wins = $trades->where('realized_pnl', '>', 0)->count();
        $losses = $trades->where('realized_pnl', '<', 0)->count();
        $grossProfit = (float) $trades->where('realized_pnl', '>', 0)->sum('realized_pnl');
        $grossLoss = abs((float) $trades->where('realized_pnl', '<', 0)->sum('realized_pnl'));
        $net = (float) $trades->sum('realized_pnl');
        $avgWin = $wins ? $grossProfit / $wins : 0;
        $avgLoss = $losses ? $grossLoss / $losses : 0;

        // Max drawdown from cumulative equity curve.
        $peak = 0; $cum = 0; $maxDd = 0;
        foreach ($trades as $t) {
            $cum += (float) $t->realized_pnl;
            $peak = max($peak, $cum);
            $maxDd = max($maxDd, $peak - $cum);
        }

        return [
            'total_trades' => $total,
            'wins' => $wins, 'losses' => $losses,
            'win_rate' => $total ? round($wins / $total * 100, 2) : null,
            'gross_profit' => round($grossProfit, 2),
            'gross_loss' => round($grossLoss, 2),
            'net_profit' => round($net, 2),
            'avg_win' => round($avgWin, 2), 'avg_loss' => round($avgLoss, 2),
            'profit_factor' => $grossLoss > 0 ? round($grossProfit / $grossLoss, 4) : ($grossProfit > 0 ? null : 0),
            'expectancy' => $total ? round($net / $total, 2) : 0,
            'max_drawdown' => round($maxDd, 2),
            'costs' => round((float) $trades->sum('costs'), 2),
        ];
    }

    public function snapshotDaily(): void
    {
        $m = $this->compute();
        PerformanceMetric::updateOrCreate(
            ['period_date' => today()->toDateString(), 'scope' => 'global', 'scope_key' => null],
            [...$m, 'total_trades' => $m['total_trades'], 'wins' => $m['wins'], 'losses' => $m['losses']]
        );
        \App\Events\PerformanceMetricsUpdated::dispatch($m);
    }

    public function byStrategy(): array
    {
        return PaperPosition::where('status', 'CLOSED')->distinct()->pluck('strategy_code')
            ->filter()->mapWithKeys(fn ($s) => [$s => $this->compute(null, $s)])->all();
    }

    public function bySymbol(): array
    {
        return PaperPosition::where('status', 'CLOSED')->distinct()->pluck('symbol')
            ->mapWithKeys(fn ($s) => [$s => $this->compute($s)])->all();
    }
}
