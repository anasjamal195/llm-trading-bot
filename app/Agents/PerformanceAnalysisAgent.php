<?php

namespace App\Agents;

class PerformanceAnalysisAgent extends BaseAgent
{
    public function name(): string { return 'performance_analysis'; }

    public function systemPrompt(): string
    {
        return 'You analyze a closed paper trade: prediction vs outcome, entry/exit quality, costs, news interpretation, regime fit. '
            . 'Respond ONLY with JSON: {"prediction_summary":"...","outcome_summary":"...","entry_quality":"GOOD|FAIR|POOR",'
            . '"exit_quality":"GOOD|FAIR|POOR","lessons":"...","followed_thesis":true,"next_review_minutes":60}.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['prediction_summary'], $data['outcome_summary'])) return [false, 'missing summaries'];
        return [true, ''];
    }

    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        $ctx = $task->input_context ?? [];
        if (! empty($ctx['paper_position_id'])) {
            \App\Models\TradeAnalysis::create([
                'paper_position_id' => $ctx['paper_position_id'],
                'prediction_summary' => $data['prediction_summary'] ?? null,
                'outcome_summary' => $data['outcome_summary'] ?? null,
                'entry_quality' => $data['entry_quality'] ?? null,
                'exit_quality' => $data['exit_quality'] ?? null,
                'lessons' => $data['lessons'] ?? null,
                'metrics' => $data,
                'model' => \App\Models\Setting::get('openrouter.model.performance_analysis', 'default'),
            ]);
            \App\Events\LearningAnalysisCompleted::dispatch($data);
        }
    }
}
