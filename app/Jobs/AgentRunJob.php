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
        if ($task->task_type === 'research_news') {
            // Feed the agent ONLY real stored headlines (from the news provider
            // or admin entries). Never let it invent news from thin air.
            $ctx['headlines'] = \App\Models\NewsArticle::where('expires_at', '>', now())
                ->orderBy('published_at', 'desc')->limit(20)->get()
                ->map(fn ($n) => ['headline' => $n->headline, 'source' => $n->source, 'url' => $n->url, 'published_at' => $n->published_at?->toIso8601String(), 'currency' => $n->currency])->all();
        }
        if ($task->task_type === 'coordinate' || $task->task_type === 'discover_pairs') {
            // Compact projector: provider context windows can be small
            // (llama via some providers is 4k). Send essentials only.
            $ctx['snapshots'] = \App\Models\MarketSnapshot::orderBy('id', 'desc')->limit(40)->get()
                ->groupBy('symbol')->map(fn ($g) => $g->first())->values()->take(13)
                ->map(fn ($s) => ['symbol' => $s->symbol, 'price' => $s->price, 'trend' => $s->trend, 'regime' => $s->regime, 'rsi' => $s->indicators['rsi14'] ?? null])->all();
            $ctx['currencies'] = \App\Models\CurrencyResearch::orderBy('id', 'desc')->limit(20)->get()
                ->groupBy('currency')->map(fn ($g) => $g->first())->values()->take(10)
                ->map(fn ($c) => ['currency' => $c->currency, 'outlook' => $c->outlook, 'confidence' => $c->confidence])->all();
            $ctx['rankings'] = \App\Models\PairRanking::orderBy('id', 'desc')->limit(26)->get()
                ->groupBy('symbol')->map(fn ($g) => $g->first())->values()->take(13)
                ->map(fn ($r) => ['symbol' => $r->symbol, 'score' => $r->score, 'suitable' => $r->suitable, 'regime' => $r->regime])->all();
            $ctx['open_positions'] = \App\Models\PaperPosition::where('status', 'OPEN')->limit(10)->get()
                ->map(fn ($p) => ['symbol' => $p->symbol, 'direction' => $p->direction, 'entry' => $p->entry_price, 'unrealized' => $p->unrealized_pnl])->all();
            $ctx['risk'] = ['kill_switch' => \App\Models\Setting::get('risk.kill_switch', ''), 'daily_pnl' => app(\App\Services\Risk\RiskManagementService::class)->dailyRealizedPnl(), 'consecutive_losses' => app(\App\Services\Risk\RiskManagementService::class)->consecutiveLosses()];
        }
        if ($task->task_type === 'evaluate_opportunity' || $task->task_type === 'select_strategy') {
            // Feed post-trade reasoning back in: recent lessons for this symbol
            // (or strategy) so agents avoid repeating documented mistakes.
            $ctx['recent_lessons'] = \App\Models\TradeAnalysis::orderBy('id', 'desc')->limit(30)->get()
                ->filter(fn ($a) => ! $task->symbol || ($a->paperPosition?->symbol === $task->symbol))
                ->take(5)->values()
                ->map(fn ($a) => ['symbol' => $a->paperPosition?->symbol, 'strategy' => $a->paperPosition?->strategy_code, 'entry_quality' => $a->entry_quality, 'exit_quality' => $a->exit_quality, 'lessons' => $a->lessons])->all();
        }
        if ($task->symbol) {
            $tf = app(\App\Services\MarketData\MarketDataService::class)->analysisTimeframe($task->symbol);
            $snap = \App\Models\MarketSnapshot::where('symbol', $task->symbol)->orderBy('id', 'desc')->first();
            $candles = \App\Models\Candle::where('symbol', $task->symbol)->where('timeframe', $tf)
                ->orderBy('candle_time', 'desc')->limit(30)->get()
                ->map(fn ($c) => ['t' => $c->candle_time->toIso8601String(), 'o' => $c->open, 'h' => $c->high, 'l' => $c->low, 'c' => $c->close])->all();
            $ctx['timeframe'] = $tf;
            $ctx['snapshot'] = $snap?->toArray();
            $ctx['recent_h1'] = array_reverse($candles);
            $ctx['open_positions'] = \App\Models\PaperPosition::where('symbol', $task->symbol)->where('status', 'OPEN')->get()->toArray();
        }
        if ($task->currency) {
            $ctx['currency_research'] = \App\Models\CurrencyResearch::where('currency', $task->currency)->orderBy('id', 'desc')->first()?->toArray();
            $ctx['news'] = \App\Models\NewsArticle::where('currency', $task->currency)->orderBy('published_at', 'desc')->limit(10)->get()
                ->map(fn ($n) => ['headline' => $n->headline, 'source' => $n->source, 'sentiment' => $n->sentiment, 'impact' => $n->impact, 'published_at' => $n->published_at?->toIso8601String()])->all();
        }
        // (discover_pairs context is built in the compact coordinate branch above.)
        if ($task->task_type === 'review_position' && isset($ctx['paper_position_id'])) {
            $ctx['position'] = \App\Models\PaperPosition::find($ctx['paper_position_id'])?->toArray();
        }
        if ($task->task_type === 'analyze_closed' && isset($ctx['paper_position_id'])) {
            $ctx['position'] = \App\Models\PaperPosition::find($ctx['paper_position_id'])?->toArray();
        }
        return $ctx;
    }
}
