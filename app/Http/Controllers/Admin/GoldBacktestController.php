<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Models\Candle;
use Illuminate\Http\Request;

class GoldBacktestController extends Controller
{
    /** List every strategy run via bot:backtest-gold-llm (strategy_code = run --name). */
    public function index()
    {
        $runs = BacktestRun::where('strategy_code', 'like', 'gold-llm-%')
            ->orderBy('id', 'desc')->paginate(20);
        return view('admin.gold.index', compact('runs'));
    }

    /** One run: summary + trades + chart data. */
    public function show(BacktestRun $run)
    {
        abort_unless(str_starts_with($run->strategy_code, 'gold-llm-'), 404);
        $trades = BacktestTrade::where('backtest_run_id', $run->id)->orderBy('id')->get();
        return view('admin.gold.show', compact('run', 'trades'));
    }

    /** Candles + trade overlays for the lightweight-charts widget. */
    public function candles(Request $request, BacktestRun $run)
    {
        abort_unless(str_starts_with($run->strategy_code, 'gold-llm-'), 404);
        $data = $request->validate(['limit' => 'nullable|integer|min:100|max:5000']);
        $trades = BacktestTrade::where('backtest_run_id', $run->id)->orderBy('id')->get();

        $from = $trades->min('opened_at');
        $to = $trades->max('closed_at');
        $pad = 24 * 3600; // one day context each side
        $candles = Candle::where('symbol', $run->symbol)->where('timeframe', $run->timeframe)
            ->when($from, fn ($q) => $q->where('candle_time', '>=', date('Y-m-d H:i:s', strtotime($from) - $pad)))
            ->when($to, fn ($q) => $q->where('candle_time', '<=', date('Y-m-d H:i:s', strtotime($to) + $pad)))
            ->orderBy('candle_time')->limit($data['limit'] ?? 4000)->get()
            ->map(fn ($c) => [
                'time' => $c->candle_time->timestamp,
                'open' => (float) $c->open, 'high' => (float) $c->high,
                'low' => (float) $c->low, 'close' => (float) $c->close,
            ])->values();

        $overlays = $trades->map(fn ($t, $k) => [
            'id' => $t->id, 'n' => $k + 1, 'dir' => $t->direction,
            'entry' => (float) $t->entry, 'exit' => (float) $t->exit,
            'sl' => $t->stop_loss !== null ? (float) $t->stop_loss : null,
            'tp' => $t->take_profit !== null ? (float) $t->take_profit : null,
            'open_t' => strtotime($t->opened_at), 'close_t' => strtotime($t->closed_at),
            'pnl' => (float) $t->pnl, 'reason' => $t->close_reason,
        ])->values();

        return response()->json([
            'symbol' => $run->symbol, 'timeframe' => $run->timeframe,
            'candles' => $candles, 'trades' => $overlays,
        ]);
    }
}
