<?php

namespace App\Agents;

class MarketAnalysisAgent extends BaseAgent
{
    public function name(): string { return 'market_analysis'; }
    public function systemPrompt(): string
    {
        return 'You are a multi-timeframe Forex technical analyst. Given OHLC summaries, indicators, currency priorities and open positions, '
            . 'evaluate the pair: trend, momentum, support/resistance, breakout/pullback/reversal potential, invalidation. '
            . 'Return scenarios with estimated (uncalibrated) probabilities. Respond ONLY with JSON: {"symbol":"EURUSD",'
            . '"trend":"UP|DOWN|RANGE|UNKNOWN","momentum":"...","support":0,"resistance":0,"scenarios":[{"name":"...","probability":0.6,"description":"..."}],'
            . '"entry_zones":[],"invalidation":"...","confidence":0.5,"next_review_minutes":5-30}.';
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
