<?php

namespace App\Jobs;

use App\Models\AgentTask;
use App\Models\Candle;
use App\Models\MarketSnapshot;
use App\Models\NewsArticle;
use App\Models\PaperPosition;
use App\Models\CurrencyResearch;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use App\Agents\ChiefTradingAgent;
use App\Agents\CurrencyResearchAgent;
use App\Agents\MarketAnalysisAgent;
use App\Agents\MarketDiscoveryAgent;
use App\Agents\NewsResearchAgent;
use App\Agents\PerformanceAnalysisAgent;
use App\Agents\StrategyImprovementAgent;
use App\Agents\StrategySelectionAgent;
use App\Agents\TradeDecisionAgent;
use App\Agents\TradeManagementAgent;


/**
 * Generic agent runner with lease-based overlap prevention and stale recovery.
 */
class AgentRunJob implements ShouldQueue
{
    use Queueable;
    public $tries = 2;
    public $timeout = 300;

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $task = AgentTask::where('id', $this->taskId)->lockForUpdate()->first();
            if (! $task || $task->status !== 'PENDING') return;
            // Skip expired.
            if ($task->scheduled_at && $task->scheduled_at->isFuture()) return;
            $task->update(['status' => 'RUNNING', 'started_at' => now(), 'lease_owner' => gethostname(), 'lease_expires_at' => now()->addMinutes(10)]);
        });

        $task = AgentTask::find($this->taskId);
        if (! $task) return;
        // Freshness gate: never authorize new trade decisions on stale market data.
        if (in_array($task->agent_name, ['trade_decision'], true)) {
            $maxStale = (int) \App\Models\Setting::get('market.max_stale_seconds', 600);
            if (! app(\App\Services\MarketData\MarketDataService::class)->isFresh($maxStale)) {
                $task->update(['status' => 'PENDING', 'scheduled_at' => now()->addHour(), 'error_message' => 'Deferred: stale market data']);
                return;
            }
        }
        $agent = $this->resolve($task->agent_name);
        if (! $agent) {
            $task->update(['status' => 'FAILED', 'error_message' => 'Unknown agent: ' . $task->agent_name]);
            return;
        }
        // Enrich input context for analysis tasks.
        $task->input_context = $this->enrich($task);
        $task->save();
        $agent->run($task->fresh());
    }

    private function resolve(string $name)
    {
        $map = [
            'market_discovery' => MarketDiscoveryAgent::class,
            'news_research' => NewsResearchAgent::class,
            'currency_research' => CurrencyResearchAgent::class,
            'market_analysis' => MarketAnalysisAgent::class,
            'strategy_selection' => StrategySelectionAgent::class,
            'trade_decision' => TradeDecisionAgent::class,
            'trade_management' => TradeManagementAgent::class,
            'performance_analysis' => PerformanceAnalysisAgent::class,
            'strategy_improvement' => StrategyImprovementAgent::class,
            'chief_trading' => ChiefTradingAgent::class,
        ];
        $class = $map[$name] ?? null;
        return $class ? app($class) : null;
    }

    private function enrich(AgentTask $task): array
    {
        $ctx = $task->input_context ?? [];
        if ($task->symbol) {
            $snap = \App\Models\MarketSnapshot::where('symbol', $task->symbol)->orderBy('id', 'desc')->first();
            $candles = \App\Models\Candle::where('symbol', $task->symbol)->where('timeframe', 'H1')
                ->orderBy('candle_time', 'desc')->limit(30)->get()
                ->map(fn ($c) => ['t' => $c->candle_time->toIso8601String(), 'o' => $c->open, 'h' => $c->high, 'l' => $c->low, 'c' => $c->close])->all();
            $ctx['snapshot'] = $snap?->toArray();
            $ctx['recent_h1'] = array_reverse($candles);
            $ctx['open_positions'] = \App\Models\PaperPosition::where('symbol', $task->symbol)->where('status', 'OPEN')->get()->toArray();
        }
        if ($task->currency) {
            $ctx['currency_research'] = \App\Models\CurrencyResearch::where('currency', $task->currency)->orderBy('id', 'desc')->first()?->toArray();
            $ctx['news'] = \App\Models\NewsArticle::where('currency', $task->currency)->orderBy('published_at', 'desc')->limit(10)->get()->toArray();
        }
        if ($task->task_type === 'discover_pairs') {
            $ctx['snapshots'] = \App\Models\MarketSnapshot::orderBy('id', 'desc')->limit(20)->get()->toArray();
            $ctx['currencies'] = \App\Models\CurrencyResearch::orderBy('id', 'desc')->limit(10)->get()->toArray();
        }
        if ($task->task_type === 'review_position' && isset($ctx['paper_position_id'])) {
            $ctx['position'] = \App\Models\PaperPosition::find($ctx['paper_position_id'])?->toArray();
        }
        if ($task->task_type === 'analyze_closed' && isset($ctx['paper_position_id'])) {
            $ctx['position'] = \App\Models\PaperPosition::find($ctx['paper_position_id'])?->toArray();
        }
        return $ctx;
    }
}
