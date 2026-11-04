<?php

namespace App\Console\Commands;

use App\Jobs\AgentRunJob;
use App\Models\AgentTask;
use App\Models\BotSession;

use Illuminate\Console\Command;

class DispatchAgents extends Command
{
    protected $signature = 'bot:dispatch-agents';
    protected $description = 'Dispatch due PENDING agent tasks to the intelligence queues';

    public function handle(): int
    {
        if (BotSession::current()->status !== BotSession::RUNNING) return 0;
        AgentTask::where('status', 'PENDING')->where('scheduled_at', '<=', now())
            ->orderBy('priority', 'desc')->orderBy('id')->limit(10)->get()
            ->each(function ($task) {
                $queue = match ($task->agent_name) {
                    'news_research' => 'news',
                    'trade_decision', 'trade_management' => 'trading',
                    'performance_analysis', 'strategy_improvement' => 'learning',
                    default => 'intelligence',
                };
                AgentRunJob::dispatch($task->id)->onQueue($queue);
            });
        return 0;
    }
}
