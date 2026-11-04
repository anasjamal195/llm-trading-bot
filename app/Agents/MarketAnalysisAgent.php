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
}
