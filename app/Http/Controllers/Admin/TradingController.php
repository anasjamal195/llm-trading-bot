<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Jobs\AgentRunJob;
use App\Models\AgentTask;
use App\Models\AuditLog;
use App\Models\ImprovementProposal;
use App\Models\ModelUsageLog;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\Setting;
use App\Models\StrategyEvaluation;
use App\Models\TradeAnalysis;
use App\Models\TradeProposal;
use App\Models\TradingStrategy;
use App\Services\Learning\BacktestService;
use App\Services\Learning\PerformanceService;
use App\Services\PaperBroker\PaperBrokerService;

use Illuminate\Http\Request;


class TradingController extends Controller
{
    public function proposals()
    {
        return view('admin.trading.proposals', ['proposals' => TradeProposal::orderBy('id', 'desc')->paginate(25)]);
    }

    public function pendingOrders()
    {
        return view('admin.trading.pending', ['orders' => PaperOrder::where('status', 'PENDING')->orderBy('id', 'desc')->paginate(25)]);
    }

    public function filledOrders()
    {
        return view('admin.trading.filled', ['orders' => PaperOrder::where('status', 'FILLED')->orderBy('id', 'desc')->paginate(25)]);
    }

    public function cancelOrder(PaperOrder $order)
    {
        $ok = app(\App\Services\PaperBroker\PaperBrokerService::class)->cancelOrder($order);
        AuditLog::log('trading', 'cancel_order', ['order_id' => $order->id, 'ok' => $ok], auth('admin')->user());
        return back()->with('status', $ok ? 'Order cancelled' : 'Order could not be cancelled');
    }

    public function openTrades()
    {
        return view('admin.trading.open', ['positions' => PaperPosition::where('status', 'OPEN')->orderBy('id', 'desc')->paginate(25)]);
    }

    public function closeTrade(PaperPosition $position)
    {
        $res = app(\App\Services\PaperBroker\PaperBrokerService::class)->closeEarly($position, 'MANUAL');
        AuditLog::log('trading', 'close_position', ['position_id' => $position->id, 'result' => $res], auth('admin')->user());
        return back()->with('status', 'Position close: ' . ($res['action'] ?? 'skipped'));
    }

    public function closedTrades(Request $request)
    {
        $q = PaperPosition::where('status', 'CLOSED')->orderBy('closed_at', 'desc');
        if ($request->symbol) $q->where('symbol', $request->symbol);
        if ($request->strategy) $q->where('strategy_code', $request->strategy);
        return view('admin.trading.closed', ['positions' => $q->paginate(25)]);
    }

    public function tradeDetail(PaperPosition $position)
    {
        return view('admin.trading.detail', [
            'position' => $position,
            'order' => $position->order,
            'proposal' => $position->order?->tradeProposal,
            'analysis' => \App\Models\TradeAnalysis::where('paper_position_id', $position->id)->first(),
            'transactions' => \App\Models\PaperTransaction::where('reference_id', $position->id)->orderBy('id')->get(),
        ]);
    }

    public function analytics(\App\Services\Learning\PerformanceService $perf)
    {
        return view('admin.trading.analytics', [
            'global' => $perf->compute(),
            'byStrategy' => $perf->byStrategy(),
            'bySymbol' => $perf->bySymbol(),
            'daily' => \App\Models\PerformanceMetric::orderBy('period_date', 'desc')->limit(30)->get(),
        ]);
    }

    public function agents()
    {
        $agents = ['market_discovery', 'news_research', 'currency_research', 'market_analysis', 'strategy_selection', 'trade_decision', 'trade_management', 'performance_analysis', 'strategy_improvement', 'chief_trading'];
        $rows = collect($agents)->map(function ($a) {
            $last = AgentTask::where('agent_name', $a)->orderBy('id', 'desc')->first();
            $usage = ModelUsageLog::where('agent_name', $a);
            $today = (clone $usage)->whereDate('created_at', today());
            return [
                'name' => $a,
                'enabled' => Setting::get("agents.{$a}.enabled", '1') === '1',
                'model' => Setting::get("openrouter.model.{$a}", Setting::get('openrouter.model.default', '')),
                'last' => $last,
                'calls' => (clone $usage)->count(),
                'tokens' => (clone $usage)->sum('input_tokens') + (clone $usage)->sum('output_tokens'),
                'cost' => round((float) (clone $usage)->sum('estimated_cost'), 4),
                'calls_today' => (clone $today)->count(),
                'cost_today' => round((float) (clone $today)->sum('estimated_cost'), 4),
                'errors' => (clone $usage)->where('status', '!=', 'OK')->count(),
                'avg_latency' => round((float) (clone $usage)->avg('latency_ms') ?? 0),
            ];
        });
        $byModel = ModelUsageLog::selectRaw('model, COUNT(*) calls, SUM(input_tokens)+SUM(output_tokens) tokens, SUM(estimated_cost) cost, AVG(latency_ms) latency')
            ->groupBy('model')->orderByDesc('cost')->get();
        $spend = [
            'today' => round((float) ModelUsageLog::whereDate('created_at', today())->sum('estimated_cost'), 4),
            'week' => round((float) ModelUsageLog::where('created_at', '>=', now()->subWeek())->sum('estimated_cost'), 4),
            'total' => round((float) ModelUsageLog::sum('estimated_cost'), 4),
        ];
        return view('admin.agents.index', ['agents' => $rows, 'tasks' => AgentTask::orderBy('id', 'desc')->paginate(25), 'byModel' => $byModel, 'spend' => $spend, 'failures' => ModelUsageLog::where('status', '!=', 'OK')->orderBy('id', 'desc')->limit(15)->get()]);
    }

    public function runAgent(Request $request, string $agent)
    {
        $request->validate(['symbol' => 'nullable|string|max:10', 'currency' => 'nullable|string|max:8']);
        $task = AgentTask::dispatchOnce([
            'task_type' => 'manual_' . $agent, 'agent_name' => $agent,
            'symbol' => $request->symbol, 'currency' => $request->currency,
            'priority' => 10, 'status' => 'PENDING',
            'input_context' => ['trigger' => 'manual', 'by' => auth('admin')->user()->email],
            'scheduled_at' => now(), 'dedupe_key' => 'manual|' . $agent . '|' . $request->symbol . '|' . date('YmdHi'),
        ]);
        \App\Jobs\AgentRunJob::dispatch($task->id)->onQueue('intelligence');
        AuditLog::log('agents', 'manual_run', ['agent' => $agent, 'task_id' => $task->id], auth('admin')->user());
        return back()->with('status', "Agent {$agent} dispatched (task #{$task->id})");
    }

    public function toggleAgent(string $agent)
    {
        $key = "agents.{$agent}.enabled";
        Setting::put($key, Setting::get($key, '1') === '1' ? '' : '1', 'agents');
        return back()->with('status', "Agent {$agent} toggled");
    }

    public function learning(\App\Services\Learning\PerformanceService $perf)
    {
        return view('admin.learning.index', [
            'analyses' => \App\Models\TradeAnalysis::orderBy('id', 'desc')->limit(20)->get(),
            'proposals' => \App\Models\ImprovementProposal::orderBy('id', 'desc')->get(),
            'evaluations' => \App\Models\StrategyEvaluation::orderBy('id', 'desc')->limit(20)->get(),
            'strategies' => \App\Models\TradingStrategy::orderBy('code')->get(),
        ]);
    }

    public function backtests()
    {
        return view('admin.learning.backtests', ['runs' => \App\Models\BacktestRun::orderBy('id', 'desc')->paginate(20)]);
    }

    public function runBacktest(Request $request, \App\Services\Learning\BacktestService $bt)
    {
        $data = $request->validate([
            'strategy_code' => 'required|string', 'symbol' => 'required|string',
            'timeframe' => 'required|string', 'from' => 'required|date', 'to' => 'required|date',
        ]);
        $res = $bt->walkForward($data['strategy_code'], $data['symbol'], $data['timeframe'], [], $data['from'], $data['to']);
        AuditLog::log('learning', 'backtest', ['result' => $res], auth('admin')->user());
        return back()->with('status', 'Backtest verdict: ' . $res['verdict'] . ' (IS net ' . $res['in_sample']['net'] . ', OOS net ' . $res['out_of_sample']['net'] . ')');
    }
}
