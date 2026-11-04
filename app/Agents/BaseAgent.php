<?php

namespace App\Agents;

use App\Events\AgentExecutionCompleted;
use App\Events\AgentExecutionFailed;
use App\Events\AgentExecutionStarted;
use App\Models\AgentTask;
use App\Models\PromptVersion;
use App\Models\Setting;
use App\Services\LLM\OpenRouterService;

use Illuminate\Support\Facades\Log;

/**
 * Base class for all LLM agents: lease a PENDING task, run structured JSON
 * chat, schema-validate, persist output. Never touches financial records.
 */
abstract class BaseAgent
{
    abstract public function name(): string;

    abstract public function systemPrompt(): string;

    /** Validate decoded JSON. Return [ok, error]. */
    abstract public function validate(array $data): array;

    public function __construct(protected OpenRouterService $llm) {}

    public function run(AgentTask $task): array
    {
        $task->update(['status' => 'RUNNING', 'started_at' => now(), 'lease_owner' => gethostname(), 'lease_expires_at' => now()->addMinutes(10)]);
        \App\Events\AgentExecutionStarted::dispatch($task);

        $prompt = PromptVersion::activeFor($this->name());
        $system = $prompt?->system_prompt ?? $this->systemPrompt();
        $result = $this->llm->chatJson($this->name(), $system, $task->input_context ?? [], ['agent_task_id' => $task->id]);

        if (! $result['ok']) {
            $task->update(['status' => 'FAILED', 'error_message' => substr($result['error'] ?? 'unknown', 0, 1000), 'retry_count' => $task->retry_count + 1]);
            app(\App\Services\System\AppLogger::class)->warning('agent', "{$this->name()} task #{$task->id} failed", ['error' => substr($result['error'] ?? 'unknown', 0, 200)]);
            \App\Events\AgentExecutionFailed::dispatch($task);
            return ['ok' => false, 'error' => $result['error'] ?? 'unknown'];
        }

        [$valid, $error] = $this->validate($result['data']);
        if (! $valid) {
            $task->update(['status' => 'FAILED', 'error_message' => 'Schema: ' . $error, 'retry_count' => $task->retry_count + 1, 'output_result' => $result['data']]);
            \App\Events\AgentExecutionFailed::dispatch($task);
            return ['ok' => false, 'error' => 'Schema: ' . $error];
        }

        $task->update(['status' => 'COMPLETED', 'output_result' => $result['data'], 'completed_at' => now(), 'next_run_at' => $this->nextRun($result['data'])]);
        $usage = $result['usage'] ?? [];
        app(\App\Services\System\AppLogger::class)->info('agent', "{$this->name()} task #{$task->id} completed", ['in_tokens' => $usage['prompt_tokens'] ?? null, 'out_tokens' => $usage['completion_tokens'] ?? null]);
        \App\Events\AgentExecutionCompleted::dispatch($task);
        $this->afterComplete($task, $result['data']);
        return ['ok' => true, 'data' => $result['data']];
    }

    protected function nextRun(array $data): ?\DateTime
    {
        $minutes = (int) ($data['next_review_minutes'] ?? 15);
        $minutes = max((int) \App\Models\Setting::get('agents.min_interval_minutes', 2), min(120, $minutes));
        return now()->addMinutes($minutes);
    }

    protected function afterComplete(AgentTask $task, array $data): void {}
}
