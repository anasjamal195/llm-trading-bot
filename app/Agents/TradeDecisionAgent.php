<?php

namespace App\Agents;

class TradeDecisionAgent extends BaseAgent
{
    public function name(): string { return 'trade_decision'; }

    public function systemPrompt(): string
    {
        return 'You convert an analyzed setup into a structured LIMIT-order trade proposal, or decline. Order types allowed: LIMIT_BUY, LIMIT_SELL. '
            . 'No market orders. Every proposal needs entry, stop loss, take profit with risk/reward >= 1.5, expiry minutes, and invalidation. '
            . 'Respond ONLY with JSON: {"decision":"PROPOSE|NO_TRADE","symbol":"EURUSD","direction":"BUY|SELL","order_type":"LIMIT_BUY|LIMIT_SELL",'
            . '"entry":0,"stop_loss":0,"take_profit":0,"strategy":"...","rationale":"...","invalidation":"...","confidence":0.5,'
            . '"expiry_minutes":120,"expected_holding_hours":12,"next_review_minutes":15}. You never execute orders.';
    }

    public function validate(array $data): array
    {
        if (($data['decision'] ?? '') === 'NO_TRADE') return [true, ''];
        foreach (['symbol', 'direction', 'order_type', 'entry', 'stop_loss', 'take_profit'] as $f) {
            if (! isset($data[$f])) return [false, "missing {$f}"];
        }
        if (! in_array($data['order_type'], ['LIMIT_BUY', 'LIMIT_SELL'], true)) return [false, 'order type must be LIMIT'];
        return [true, ''];
    }

    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        app(\App\Services\Trading\ProposalService::class)->handleDecision($task, $data);
    }
}
