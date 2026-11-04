<?php

namespace App\Jobs;

use App\Models\AgentTask;
use App\Models\BotSession;
use App\Models\Candle;
use App\Models\Setting;
use App\Services\MarketData\MarketDataService;
use App\Services\PaperBroker\PaperBrokerService;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchCandlesJob implements ShouldQueue
{
    use Queueable;
    public $tries = 3;
    public $timeout = 120;

    public function __construct(public string $symbol, public string $timeframe) {}

    public function handle(MarketDataService $md, PaperBrokerService $broker): void
    {
        $stats = $md->sync($this->symbol, $this->timeframe);

        $latest = Candle::where('symbol', $this->symbol)->where('timeframe', $this->timeframe)
            ->orderBy('candle_time', 'desc')->first();
        if ($latest) {
            CandleUpdated::dispatch($this->symbol, $this->timeframe, [
                'time' => $latest->candle_time->toIso8601String(), 'open' => $latest->open,
                'high' => $latest->high, 'low' => $latest->low, 'close' => $latest->close,
            ]);
            // Candle-close trigger: evaluate pending orders + monitor positions on M1.
            if ($this->timeframe === 'M1' && in_array(BotSession::current()->status, [BotSession::RUNNING, BotSession::PAUSED], true)) {
                $broker->processPendingOrders($this->symbol, $latest);
                $broker->monitorPositions($this->symbol, $latest);
            }
        }

        // Chain next analysis if warranted (cooldown-gated).
        if ($this->timeframe === 'M1') {
            $lastTask = \App\Models\AgentTask::where('symbol', $this->symbol)
                ->where('task_type', 'analyze_pair')->where('status', 'COMPLETED')
                ->orderBy('completed_at', 'desc')->first();
            $cooldown = (int) \App\Models\Setting::get('agents.pair_cooldown_minutes', 15);
            if (! $lastTask || $lastTask->completed_at->lt(now()->subMinutes($cooldown))) {
                \App\Models\AgentTask::dispatchOnce([
                    'task_type' => 'analyze_pair', 'agent_name' => 'market_analysis',
                    'symbol' => $this->symbol, 'priority' => 5, 'status' => 'PENDING',
                    'input_context' => ['trigger' => 'candle_close'],
                    'scheduled_at' => now(), 'dedupe_key' => 'analyze|' . $this->symbol . '|' . date('YmdHi'),
                ]);
            }
        }
    }
}
