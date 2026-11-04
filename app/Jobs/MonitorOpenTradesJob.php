<?php

namespace App\Jobs;

use App\Jobs\AnalyzeClosedTradeJob;
use App\Models\AgentTask;
use App\Models\Candle;
use App\Models\PaperPosition;
use App\Models\TradingSymbol;
use App\Services\PaperBroker\PaperBrokerService;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MonitorOpenTradesJob implements ShouldQueue
{
    use Queueable;
    public $tries = 2;
    public $timeout = 180;

    public function handle(PaperBrokerService $broker): void
    {
        foreach (TradingSymbol::where('is_enabled', true)->pluck('symbol') as $symbol) {
            $latest = Candle::where('symbol', $symbol)->where('timeframe', 'M1')->orderBy('candle_time', 'desc')->first();
            if (! $latest) continue;
            $results = $broker->monitorPositions($symbol, $latest);
            foreach ($results as $r) {
                if (($r['action'] ?? '') === 'CLOSED') {
                    AnalyzeClosedTradeJob::dispatch($r['position_id'])->onQueue('learning');
                }
            }
        }
        // Queue agent reviews for positions due for review.
        PaperPosition::where('status', 'OPEN')->where('next_review_at', '<=', now())->get()
            ->each(function ($p) {
                AgentTask::dispatchOnce([
                    'task_type' => 'review_position', 'agent_name' => 'trade_management',
                    'symbol' => $p->symbol, 'priority' => 7, 'status' => 'PENDING',
                    'input_context' => ['paper_position_id' => $p->id],
                    'scheduled_at' => now(), 'dedupe_key' => 'review|' . $p->id . '|' . date('YmdHi'),
                ]);
            });
    }
}
