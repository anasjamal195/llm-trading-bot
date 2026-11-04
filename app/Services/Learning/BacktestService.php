<?php

namespace App\Services\Learning;

use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Models\Candle;


/**
 * Deterministic rule-based backtest for candidate strategy configs.
 * Optimization set and validation set must differ (walk-forward enforced by caller).
 */
class BacktestService
{
    public function run(BacktestRun $run): array
    {
        $run->update(['status' => 'RUNNING']);
        try {
            $candles = Candle::where('symbol', $run->symbol)
                ->where('timeframe', $run->timeframe)
                ->when($run->from, fn ($q) => $q->where('candle_time', '>=', $run->from))
                ->when($run->to, fn ($q) => $q->where('candle_time', '<=', $run->to))
                ->orderBy('candle_time')->get();

            $params = $run->parameters ?? [];
            $fast = (int) ($params['fast_ema'] ?? 12);
            $slow = (int) ($params['slow_ema'] ?? 26);
            $slAtr = (float) ($params['sl_atr_mult'] ?? 2.0);
            $tpAtr = (float) ($params['tp_atr_mult'] ?? 3.0);

            $closes = $candles->pluck('close')->map(fn ($v) => (float) $v)->all();
            $ta = app(\App\Services\Trading\TechnicalAnalysisService::class);
            $trades = [];
            $open = null;

            foreach ($candles as $i => $c) {
                $window = array_slice($closes, 0, $i + 1);
                $f = $ta->ema($window, $fast);
                $s = $ta->ema($window, $slow);
                $atr = $ta->atr(
                    array_slice($candles->pluck('high')->map(fn ($v) => (float) $v)->all(), 0, $i + 1),
                    array_slice($candles->pluck('low')->map(fn ($v) => (float) $v)->all(), 0, $i + 1),
                    $window, 14
                );
                if ($f === null || $s === null || ! $atr) continue;
                $price = (float) $c->close;

                if ($open === null) {
                    if ($f > $s) $open = ['direction' => 'BUY', 'entry' => $price, 'sl' => $price - $slAtr * $atr, 'tp' => $price + $tpAtr * $atr, 'at' => $c->candle_time];
                    elseif ($f < $s) $open = ['direction' => 'SELL', 'entry' => $price, 'sl' => $price + $slAtr * $atr, 'tp' => $price - $tpAtr * $atr, 'at' => $c->candle_time];
                } else {
                    $exit = null;
                    $high = (float) $c->high; $low = (float) $c->low;
                    $slHit = $open['direction'] === 'BUY' ? $low <= $open['sl'] : $high >= $open['sl'];
                    $tpHit = $open['direction'] === 'BUY' ? $high >= $open['tp'] : $low <= $open['tp'];
                    if ($slHit && $tpHit) $exit = $open['sl']; // conservative
                    elseif ($slHit) $exit = $open['sl'];
                    elseif ($tpHit) $exit = $open['tp'];
                    if ($exit !== null) {
                        $pnl = ($exit - $open['entry']) * ($open['direction'] === 'BUY' ? 1 : -1) * 100000 * 0.01; // 0.01 lot approx
                        $trades[] = [...$open, 'exit' => $exit, 'pnl' => round($pnl, 2), 'closed_at' => $c->candle_time];
                        $open = null;
                    }
                }
            }

            $net = array_sum(array_column($trades, 'pnl'));
            $wins = count(array_filter($trades, fn ($t) => $t['pnl'] > 0));
            $results = ['trades' => count($trades), 'wins' => $wins, 'net' => round($net, 2), 'win_rate' => $trades ? round($wins / count($trades) * 100, 2) : 0];
            BacktestTrade::where('backtest_run_id', $run->id)->delete();
            foreach ($trades as $t) {
                BacktestTrade::create(['backtest_run_id' => $run->id, 'direction' => $t['direction'], 'entry' => $t['entry'], 'exit' => $t['exit'], 'pnl' => $t['pnl'], 'opened_at' => $t['at'], 'closed_at' => $t['closed_at']]);
            }
            $run->update(['status' => 'COMPLETED', 'results' => $results]);
            return $results;
        } catch (\Throwable $e) {
            $run->update(['status' => 'FAILED', 'results' => ['error' => substr($e->getMessage(), 0, 500)]]);
            throw $e;
        }
    }

    /** Walk-forward: split range 70/30 in-sample/out-of-sample, run both, compare. */
    public function walkForward(string $strategy, string $symbol, string $timeframe, array $params, $from, $to): array
    {
        $fromTs = strtotime($from); $toTs = strtotime($to);
        $split = $fromTs + (int) (($toTs - $fromTs) * 0.7);
        $is = BacktestRun::create(['strategy_code' => $strategy, 'symbol' => $symbol, 'timeframe' => $timeframe, 'from' => date('Y-m-d H:i:s', $fromTs), 'to' => date('Y-m-d H:i:s', $split), 'parameters' => $params, 'status' => 'PENDING']);
        $oos = BacktestRun::create(['strategy_code' => $strategy, 'symbol' => $symbol, 'timeframe' => $timeframe, 'from' => date('Y-m-d H:i:s', $split), 'to' => date('Y-m-d H:i:s', $toTs), 'parameters' => $params, 'status' => 'PENDING']);
        $isRes = $this->run($is);
        $oosRes = $this->run($oos);
        $verdict = ($oosRes['net'] ?? 0) > 0 && ($isRes['net'] ?? 0) > 0 ? 'PROMISING' : 'REJECT';
        return ['in_sample' => $isRes, 'out_of_sample' => $oosRes, 'verdict' => $verdict, 'in_sample_id' => $is->id, 'out_of_sample_id' => $oos->id];
    }
}
