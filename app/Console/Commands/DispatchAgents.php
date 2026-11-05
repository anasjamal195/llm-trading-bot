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
        // Keep the top of the funnel fresh: re-run discovery hourly so pair
        // rankings (which gate the analysis→strategy→decision chain) don't rot.
        if (! AgentTask::where('task_type', 'discover_pairs')->where('status', 'COMPLETED')->where('created_at', '>=', now()->subHour())->exists()) {
            AgentTask::dispatchOnce([
                'task_type' => 'discover_pairs', 'agent_name' => 'market_discovery',
                'priority' => 9, 'status' => 'PENDING',
                'input_context' => ['trigger' => 'hourly_refresh'],
                'scheduled_at' => now(), 'dedupe_key' => 'discover|' . date('YmdH'),
            ]);
        }
        // Chief coordinator pulse every 30 minutes.
        if (! AgentTask::where('agent_name', 'chief_trading')->where('status', 'COMPLETED')->where('created_at', '>=', now()->subMinutes(30))->exists()) {
            AgentTask::dispatchOnce([
                'task_type' => 'coordinate', 'agent_name' => 'chief_trading',
                'priority' => 8, 'status' => 'PENDING',
                'input_context' => ['trigger' => 'pulse'],
                'scheduled_at' => now(), 'dedupe_key' => 'chief|' . date('YmdHi', (int) (time() / 1800) * 1800),
            ]);
        }
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
