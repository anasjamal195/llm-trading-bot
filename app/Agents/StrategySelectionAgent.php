<?php

namespace App\Agents;

class StrategySelectionAgent extends BaseAgent
{
    public const STRATEGIES = ['TREND_PULLBACK', 'MOMENTUM_BREAKOUT', 'RANGE_REVERSAL', 'SR_REJECTION', 'VOLATILITY_EXPANSION', 'NO_TRADE'];

    public function name(): string { return 'strategy_selection'; }

    public function systemPrompt(): string
    {
        return 'You pick a trading strategy for the current regime. Choices: TREND_PULLBACK, MOMENTUM_BREAKOUT, RANGE_REVERSAL, '
            . 'SR_REJECTION, VOLATILITY_EXPANSION, or NO_TRADE (when nothing fits — this is often correct). '
            . 'Respond ONLY with JSON: {"strategy":"...","regime":"...","reason":"...","confidence":0.5,"next_review_minutes":10-60}.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['strategy']) || ! in_array($data['strategy'], self::STRATEGIES, true)) return [false, 'bad strategy'];
        return [true, ''];
    }
}
