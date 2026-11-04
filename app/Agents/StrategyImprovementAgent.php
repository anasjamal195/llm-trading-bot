<?php

namespace App\Agents;

class StrategyImprovementAgent extends BaseAgent
{
    public function name(): string { return 'strategy_improvement'; }

    public function systemPrompt(): string
    {
        return 'You review aggregated trade performance and propose strategy improvements as CANDIDATE configurations only. '
            . 'You never modify live trading rules. Every proposal needs backtest + walk-forward validation before promotion. '
            . 'Respond ONLY with JSON: {"proposals":[{"title":"...","hypothesis":"...","candidate_config":{},"target_strategy":"..."}],'
            . '"next_review_minutes":240}. Optimize risk-adjusted net profit after costs, not win rate.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['proposals']) || ! is_array($data['proposals'])) return [false, 'missing proposals'];
        return [true, ''];
    }

    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        foreach ($data['proposals'] ?? [] as $p) {
            if (empty($p['title'])) continue;
            \App\Models\ImprovementProposal::create([
                'title' => substr($p['title'], 0, 255),
                'hypothesis' => $p['hypothesis'] ?? null,
                'candidate_config' => $p['candidate_config'] ?? [],
                'status' => 'DRAFT',
            ]);
        }
    }
}
