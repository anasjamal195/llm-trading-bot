<?php

namespace App\Agents;

class TradeManagementAgent extends BaseAgent
{
    public function name(): string { return 'trade_management'; }

    public function systemPrompt(): string
    {
        return 'You review an open paper position: thesis, current price, P&L, SL/TP, market structure, news. Recommend one of '
            . 'HOLD, CLOSE_EARLY, MODIFY_SL, MODIFY_TP, NO_ACTION with explanation. You cannot override hard risk limits. '
            . 'Respond ONLY with JSON: {"recommendation":"...","new_sl":0,"new_tp":0,"explanation":"...","confidence":0.5,"next_review_minutes":5-30}.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['recommendation'])) return [false, 'missing recommendation'];
        if (! in_array($data['recommendation'], ['HOLD', 'CLOSE_EARLY', 'MODIFY_SL', 'MODIFY_TP', 'NO_ACTION'], true)) return [false, 'bad recommendation'];
        return [true, ''];
    }

    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        app(\App\Services\Trading\TradeManagementService::class)->applyRecommendation($task, $data);
    }
}
