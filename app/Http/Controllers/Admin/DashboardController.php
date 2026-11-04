<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Models\AgentTask;
use App\Models\AuditLog;
use App\Models\BotSession;
use App\Models\Candle;
use App\Models\CurrencyResearch;
use App\Models\MarketSnapshot;
use App\Models\NewsArticle;
use App\Models\PairRanking;
use App\Models\PaperAccountSnapshot;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\SystemHealthLog;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;
use App\Services\Learning\PerformanceService;



class DashboardController extends Controller
{
    public function index(PerformanceService $perf)
    {
        $account = TradingAccount::where('is_active', true)->first();
        $unrealized = (float) PaperPosition::where('status', 'OPEN')->sum('unrealized_pnl');
        $m = $perf->compute();
        return view('admin.dashboard.index', [
            'bot' => BotSession::current(),
            'account' => $account,
            'unrealized' => $unrealized,
            'equity' => $account ? (float) $account->balance + $unrealized : 0,
            'metrics' => $m,
            'openCount' => PaperPosition::where('status', 'OPEN')->count(),
            'pendingCount' => PaperOrder::where('status', 'PENDING')->count(),
            'tasks' => AgentTask::orderBy('id', 'desc')->limit(10)->get(),
            'rankings' => PairRanking::orderBy('id', 'desc')->limit(7)->get(),
            'currencies' => CurrencyResearch::orderBy('id', 'desc')->limit(8)->get(),
            'news' => NewsArticle::orderBy('published_at', 'desc')->limit(6)->get(),
            'snapshots' => MarketSnapshot::orderBy('id', 'desc')->limit(7)->get(),
            'recentPositions' => PaperPosition::orderBy('id', 'desc')->limit(8)->get(),
            'equityCurve' => \App\Models\PaperAccountSnapshot::orderBy('snapshot_date')->limit(60)->get(),
            'health' => SystemHealthLog::orderBy('id', 'desc')->limit(5)->get(),
        ]);
    }

    public function market()
    {
        $symbols = \App\Models\TradingSymbol::orderBy('symbol')->get()->map(function ($s) {
            $s->last_snapshot = MarketSnapshot::where('symbol', $s->symbol)->orderBy('id', 'desc')->first();
            $s->ranking = PairRanking::where('symbol', $s->symbol)->orderBy('id', 'desc')->first();
            $s->last_candle = \App\Models\Candle::where('symbol', $s->symbol)->where('timeframe', 'M1')->orderBy('candle_time', 'desc')->first();
            return $s;
        });
        return view('admin.market.index', ['symbols' => $symbols]);
    }

    public function symbol(string $symbol)
    {
        $candles = \App\Models\Candle::where('symbol', strtoupper($symbol))->where('timeframe', request('tf', 'H1'))
            ->orderBy('candle_time', 'desc')->limit(200)->get()->reverse()->values();
        return view('admin.market.show', [
            'symbol' => strtoupper($symbol), 'candles' => $candles,
            'timeframe' => request('tf', 'H1'),
            'snapshot' => MarketSnapshot::where('symbol', strtoupper($symbol))->orderBy('id', 'desc')->first(),
            'positions' => PaperPosition::where('symbol', strtoupper($symbol))->orderBy('id', 'desc')->limit(20)->get(),
        ]);
    }

    public function currencies()
    {
        return view('admin.currencies.index', [
            'currencies' => CurrencyResearch::orderBy('id', 'desc')->limit(50)->get()->groupBy('currency')->map->first(),
            'news' => NewsArticle::orderBy('published_at', 'desc')->limit(30)->get(),
            'events' => \App\Models\EconomicEvent::where('event_time', '>=', now()->subDays(7))->orderBy('event_time', 'desc')->limit(30)->get(),
        ]);
    }

    public function activity()
    {
        return view('admin.activity.index', ['logs' => AuditLog::orderBy('id', 'desc')->paginate(50)]);
    }
}
