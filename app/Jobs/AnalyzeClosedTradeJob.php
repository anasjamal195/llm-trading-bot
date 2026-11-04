<?php

namespace App\Jobs;

use App\Jobs\AgentRunJob;
use App\Models\AgentTask;
use App\Models\PaperPosition;
use App\Models\TradeAnalysis;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeClosedTradeJob implements ShouldQueue
{
    use Queueable;
    public $tries = 2;
    public $timeout = 300;

    public function __construct(public int $positionId) {}

    public function handle(): void
    {
        $position = PaperPosition::find($this->positionId);
        if (! $position || $position->status !== 'CLOSED') return;
        if (\App\Models\TradeAnalysis::where('paper_position_id', $position->id)->exists()) return;
        $task = AgentTask::dispatchOnce([
            'task_type' => 'analyze_closed', 'agent_name' => 'performance_analysis',
            'symbol' => $position->symbol, 'priority' => 4, 'status' => 'PENDING',
            'input_context' => ['paper_position_id' => $position->id],
            'scheduled_at' => now(), 'dedupe_key' => 'analyze_closed|' . $position->id,
        ]);
        AgentRunJob::dispatch($task->id)->onQueue('learning');
    }
}
