<?php

namespace App\Agents;

/**
 * Central intelligence coordinator. No DB/execution authority:
 * reads other agents' outputs and dispatches follow-up tasks.
 */
class ChiefTradingAgent extends BaseAgent
{
    public function name(): string { return 'chief_trading'; }

    public function systemPrompt(): string
    {
        return 'You are the chief coordinator of a Forex paper-trading system. Given ranked pairs, currency priorities, open positions, '
            . 'risk state and recent agent outputs, decide what happens next: which pairs deserve deep analysis, whether a trade decision '
            . 'task should be created, when each analysis should re-run, whether news research is needed. You have NO execution authority. '
            . 'Respond ONLY with JSON: {"actions":[{"task_type":"analyze_currency|evaluate_opportunity|research_news|review_position|discover_pairs",'
            . '"symbol":null,"currency":null,"priority":1-10,"reason":"..."}],"analysis_notes":"...","next_review_minutes":5-30}.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['actions']) || ! is_array($data['actions'])) return [false, 'missing actions'];
        return [true, ''];
    }

    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        $allowed = ['analyze_currency', 'evaluate_opportunity', 'research_news', 'review_position', 'discover_pairs'];
        $agentMap = [
            'analyze_currency' => 'market_analysis', 'evaluate_opportunity' => 'trade_decision',
            'research_news' => 'news_research', 'review_position' => 'trade_management',
            'discover_pairs' => 'market_discovery',
        ];
        foreach ($data['actions'] ?? [] as $a) {
            $type = $a['task_type'] ?? '';
            if (! in_array($type, $allowed, true)) continue;
            $dedupe = $type . '|' . ($a['symbol'] ?? '') . '|' . ($a['currency'] ?? '');
            \App\Models\AgentTask::dispatchOnce([
                'task_type' => $type,
                'agent_name' => $agentMap[$type],
                'symbol' => $a['symbol'] ?? null,
                'currency' => $a['currency'] ?? null,
                'priority' => max(1, min(10, (int) ($a['priority'] ?? 5))),
                'status' => 'PENDING',
                'input_context' => ['requested_by' => 'chief_trading', 'reason' => $a['reason'] ?? '', 'chief_task_id' => $task->id],
                'scheduled_at' => now(),
                'dedupe_key' => $dedupe,
            ]);
        }
    }
}
