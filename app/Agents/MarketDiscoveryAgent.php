<?php

namespace App\Agents;

use App\Models\PairRanking;

class MarketDiscoveryAgent extends BaseAgent
{
    public function name(): string { return 'market_discovery'; }

    public function systemPrompt(): string
    {
        return 'You are a Forex market regime analyst. Given market snapshots for currency pairs, rank suitability for trading. '
            . 'You may reject ALL pairs by returning an empty ranked list with suitable=false for each. Never force trading. '
            . 'Respond ONLY with JSON: {"rankings":[{"symbol":"EURUSD","score":0-100,"suitable":bool,"regime":"TRENDING|RANGING|VOLATILE|UNKNOWN","reasons":"..."}],'
            . '"session":"...","notes":"...","next_review_minutes":5-60}. Probabilities are estimates, not calibrated.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['rankings']) || ! is_array($data['rankings'])) return [false, 'missing rankings'];
        foreach ($data['rankings'] as $r) {
            if (! isset($r['symbol'], $r['score'], $r['suitable'])) return [false, 'ranking missing fields'];
        }
        return [true, ''];
    }

    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        foreach ($data['rankings'] ?? [] as $r) {
            PairRanking::create([
                'symbol' => $r['symbol'], 'score' => $r['score'] ?? 0,
                'suitable' => (bool) ($r['suitable'] ?? false),
                'regime' => $r['regime'] ?? null, 'reasons' => $r['reasons'] ?? null,
            ]);
            // Automatic timeframe selection: pin the symbol's analysis lens to its regime.
            $tf = \App\Services\MarketData\MarketDataService::regimeTimeframe($r['regime'] ?? 'UNKNOWN');
            \App\Models\TradingSymbol::where('symbol', $r['symbol'])->update(['analysis_timeframe' => $tf]);
        }
        app(\App\Services\System\AppLogger::class)->info('agent', 'Discovery updated analysis timeframes', ['rankings' => count($data['rankings'] ?? [])]);
        \App\Events\PairRankingUpdated::dispatch($data);
    }
}
