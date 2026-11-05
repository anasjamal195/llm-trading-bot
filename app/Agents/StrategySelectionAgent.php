<?php

namespace App\Agents;

class StrategySelectionAgent extends BaseAgent
{
    public const STRATEGIES = ['TREND_PULLBACK', 'MOMENTUM_BREAKOUT', 'RANGE_REVERSAL', 'SR_REJECTION', 'VOLATILITY_EXPANSION', 'NO_TRADE'];

    public function name(): string { return 'strategy_selection'; }

    public function systemPrompt(): string
    {
        return 'You pick a SCALPING strategy for the current regime on a short timeframe (M1/M5 structure). '
            . 'Choices: TREND_PULLBACK, MOMENTUM_BREAKOUT, RANGE_REVERSAL, '
            . 'SR_REJECTION, VOLATILITY_EXPANSION, or NO_TRADE (when nothing fits — this is often correct). '
            . 'Only setups that can play out within a few hours qualify; otherwise choose NO_TRADE. '
            . 'Respond ONLY with JSON: {"strategy":"...","regime":"...","reason":"...","confidence":0.5,"next_review_minutes":10-60}.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['strategy']) || ! in_array($data['strategy'], self::STRATEGIES, true)) return [false, 'bad strategy'];
        return [true, ''];
    }

    /**
     * Chain the pipeline: a selected (non-NO_TRADE) strategy moves on to a
     * concrete trade decision. NO_TRADE ends the chain with a log line.
     */
    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        $strategy = $data['strategy'] ?? 'NO_TRADE';
        if ($strategy === 'NO_TRADE') {
            app(\App\Services\System\AppLogger::class)->info('agent', "Strategy NO_TRADE for {$task->symbol}", ['reason' => $data['reason'] ?? null]);
            return;
        }
        \App\Models\AgentTask::dispatchOnce([
            'task_type' => 'evaluate_opportunity', 'agent_name' => 'trade_decision',
            'symbol' => $task->symbol, 'priority' => 8, 'status' => 'PENDING',
            'input_context' => ['strategy_task_id' => $task->id, 'strategy' => $strategy, 'analysis' => $task->input_context['analysis'] ?? null, 'timeframe' => $task->input_context['timeframe'] ?? null],
            'scheduled_at' => now(), 'dedupe_key' => 'decide|' . $task->symbol . '|' . $strategy . '|' . date('YmdHi'),
        ]);
    }
}
