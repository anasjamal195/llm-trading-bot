<?php

namespace App\Agents;

class TradeManagementAgent extends BaseAgent
{
    public function name(): string { return 'trade_management'; }

    public function systemPrompt(): string
    {
        return 'You monitor an open paper position with FIXED stop loss and take profit — you cannot move TP, and you cannot close early '
            . 'on opinion. Report one of HOLD or NO_ACTION with current assessment. Recommend CLOSE_EARLY only when the position has passed '
            . 'its expected close time (max_hold_until) or the original thesis is demonstrably broken by new facts. '
            . 'You may recommend MODIFY_SL only to tighten it (reduce risk), never to widen. '
            . 'Respond ONLY with JSON: {"recommendation":"HOLD|CLOSE_EARLY|MODIFY_SL|NO_ACTION","new_sl":0,'
            . '"past_expected_time":false,"thesis_broken":false,"explanation":"...","confidence":0.5,"next_review_minutes":5-30}.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['recommendation'])) return [false, 'missing recommendation'];
        if (! in_array($data['recommendation'], ['HOLD', 'CLOSE_EARLY', 'MODIFY_SL', 'NO_ACTION'], true)) return [false, 'bad recommendation'];
        return [true, ''];
    }

    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        app(\App\Services\Trading\TradeManagementService::class)->applyRecommendation($task, $data);
    }
}
