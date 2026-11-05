<?php

namespace App\Agents;

class MarketAnalysisAgent extends BaseAgent
{
    public function name(): string { return 'market_analysis'; }

    public function systemPrompt(): string
    {
        return 'You are a multi-timeframe Forex scalping analyst combining chart structure with the latest news. '
            . 'For the pair: (1) state direction UP/DOWN/RANGE with an estimated probability (uncalibrated estimate, not a guarantee), '
            . '(2) entry zone, (3) stop loss AND take profit as prices AND as % distance from entry, '
            . '(4) your confidence %, (5) key support/resistance levels, (6) invalidation condition, (7) what to watch next. '
            . 'Weigh both the graph and the news; say which one dominates and why. No trade without both agreeing counts as high confidence. '
            . 'Respond ONLY with JSON: {"symbol":"EURUSD","direction":"UP|DOWN|RANGE","probability":0.65,"trend":"UP|DOWN|RANGE|UNKNOWN",'
            . '"momentum":"...","entry_zone":[0,0],"stop_loss":0,"stop_pct":0,"take_profit":0,"tp_pct":0,"confidence":0.65,'
            . '"key_levels":[],"scenarios":[{"name":"...","probability":0.6,"description":"..."}],"invalidation":"...","what_to_watch":"...",'
            . '"news_vs_graph":"...","next_review_minutes":5-30}.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['symbol'], $data['trend'])) return [false, 'missing symbol/trend'];
        return [true, ''];
    }

    /**
     * Chain the pipeline: a freshly analyzed pair that is currently ranked
     * suitable moves on to strategy selection. Without this link, analysis
     * results go nowhere and the bot can never trade.
     */
    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        if (! $task->symbol) return;
        $ranking = \App\Models\PairRanking::where('symbol', $task->symbol)->orderBy('id', 'desc')->first();
        if (! $ranking || ! $ranking->suitable || $ranking->created_at->lt(now()->subHours(6))) {
            app(\App\Services\System\AppLogger::class)->info('agent', "Skipping strategy selection for {$task->symbol}: no fresh suitable ranking");
            return;
        }
        \App\Models\AgentTask::dispatchOnce([
            'task_type' => 'select_strategy', 'agent_name' => 'strategy_selection',
            'symbol' => $task->symbol, 'priority' => 7, 'status' => 'PENDING',
            'input_context' => ['analysis_task_id' => $task->id, 'analysis' => $data, 'timeframe' => $task->input_context['timeframe'] ?? null],
            'scheduled_at' => now(), 'dedupe_key' => 'strategy|' . $task->symbol . '|' . date('YmdHi'),
        ]);
    }
}
