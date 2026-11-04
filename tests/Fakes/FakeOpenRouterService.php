<?php

namespace Tests\Fakes;

use App\Models\ModelUsageLog;
use App\Services\LLM\OpenRouterService;

/**
 * Deterministic stand-in for OpenRouter: returns canned, schema-valid agent
 * outputs. Used ONLY in tests/simulation — never in production.
 */
class FakeOpenRouterService extends OpenRouterService
{
    public function __construct() {}

    public function isConfigured(): bool { return true; }

    public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
    {
        $data = match ($agent) {
            'market_discovery' => ['rankings' => [['symbol' => 'EURUSD', 'score' => 80, 'suitable' => true, 'regime' => 'RANGING', 'reasons' => 'simulated range']], 'session' => 'sim', 'notes' => '', 'next_review_minutes' => 15],
            'trade_decision' => ['decision' => 'PROPOSE', 'symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'LIMIT_BUY', 'entry' => 1.1000, 'stop_loss' => 1.0980, 'take_profit' => 1.1032, 'strategy' => 'RANGE_REVERSAL', 'rationale' => 'simulated setup', 'invalidation' => 'break below 1.0980', 'confidence' => 0.7, 'expiry_minutes' => 120, 'expected_holding_hours' => 12, 'next_review_minutes' => 15],
            'performance_analysis' => ['prediction_summary' => 'sim predicted up', 'outcome_summary' => 'sim went up', 'entry_quality' => 'GOOD', 'exit_quality' => 'GOOD', 'lessons' => 'sim lesson', 'followed_thesis' => true, 'next_review_minutes' => 60],
            default => ['ok' => true, 'next_review_minutes' => 15],
        };
        ModelUsageLog::create([
            'agent_task_id' => $opts['agent_task_id'] ?? null, 'agent_name' => $agent,
            'model' => 'sim/stub', 'input_tokens' => 100, 'output_tokens' => 50,
            'latency_ms' => 5, 'estimated_cost' => round((100 * 1.00 + 50 * 2.00) / 1000000, 6),
            'status' => 'OK',
        ]);
        return ['ok' => true, 'data' => $data, 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]];
    }
}
