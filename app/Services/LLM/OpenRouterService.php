<?php

namespace App\Services\LLM;

use App\Models\ModelUsageLog;
use App\Models\Setting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Centralized OpenRouter gateway. All LLM communication goes through here.
 * Credentials are database-driven (settings table), never hardcoded.
 */
class OpenRouterService
{
    public function baseUrl(): string
    {
        return (string) (Setting::get('openrouter.base_url', env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1')));
    }

    public function apiKey(): string
    {
        return (string) Setting::get('openrouter.api_key', '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function modelFor(string $agent): string
    {
        return (string) Setting::get(
            "openrouter.model.{$agent}",
            Setting::get('openrouter.model.default', 'meta-llama/llama-3.3-70b-instruct')
        );
    }

    /**
     * Chat completion expecting structured JSON. Returns ['ok'=>bool,'data'=>array|null,'raw'=>string,'usage'=>[...]].
     */
    public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
    {
        $started = (int) (microtime(true) * 1000);
        $model = $opts['model'] ?? $this->modelFor($agent);
        $taskId = $opts['agent_task_id'] ?? null;

        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'OpenRouter API key not configured'];
        }

        // Global call budget guard.
        $budget = (int) Setting::get('openrouter.daily_budget', 500);
        $today = ModelUsageLog::whereDate('created_at', today())->count();
        if ($today >= $budget) {
            return ['ok' => false, 'error' => 'Daily LLM call budget exhausted'];
        }

        $temperature = (float) Setting::get('openrouter.temperature', 0.2);
        $maxTokens = (int) Setting::get('openrouter.max_tokens', 1500);
        $timeout = (int) Setting::get('openrouter.timeout', 60);
        $retries = (int) Setting::get('openrouter.retries', 2);

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => is_string($user) ? $user : json_encode($user)],
            ],
        ];

        $attempt = 0; $lastError = '';
        while ($attempt <= $retries) {
            try {
                $res = Http::timeout($timeout)->acceptJson()
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey(),
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => 'LLM Trading Bot',
                    ])->post($this->baseUrl() . '/chat/completions', $payload);

                if ($res->status() === 429) {
                    $lastError = 'Rate limited (429)';
                    $attempt++; sleep(min(10, 2 ** $attempt)); continue;
                }
                if ($res->failed()) {
                    $lastError = 'HTTP ' . $res->status() . ': ' . substr($res->body(), 0, 300);
                    if ($res->serverError()) { $attempt++; sleep(2); continue; }
                    break;
                }
                $json = $res->json();
                $content = $json['choices'][0]['message']['content'] ?? '';
                $usage = $json['usage'] ?? [];
                $data = $this->extractJson($content);
                $this->logUsage($agent, $model, $taskId, $usage, $started, $data === null ? 'INVALID_JSON' : 'OK', $data === null ? substr($content, 0, 500) : null);
                if ($data === null) {
                    return ['ok' => false, 'error' => 'Invalid JSON from model', 'raw' => substr($content, 0, 2000)];
                }
                return ['ok' => true, 'data' => $data, 'raw' => $content, 'usage' => $usage];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('OpenRouter request failed', ['agent' => $agent, 'attempt' => $attempt, 'error' => $lastError]);
                $attempt++; sleep(2);
            }
        }
        $this->logUsage($agent, $model, $taskId, [], $started, 'FAILED', $lastError);
        return ['ok' => false, 'error' => $lastError];
    }

    private function extractJson(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;
        // Recovery: first {...} block.
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }

    private function logUsage(string $agent, string $model, $taskId, array $usage, int $started, string $status, ?string $error): void
    {
        try {
            $in = (int) ($usage['prompt_tokens'] ?? 0);
            $out = (int) ($usage['completion_tokens'] ?? 0);
            ModelUsageLog::create([
                'agent_task_id' => $taskId,
                'agent_name' => $agent,
                'model' => $model,
                'input_tokens' => $in ?: null,
                'output_tokens' => $out ?: null,
                'latency_ms' => (int) (microtime(true) * 1000) - $started,
                'estimated_cost' => round(($in * 0.000001 + $out * 0.000002), 6),
                'status' => $status,
                'error' => $error ? substr($error, 0, 1000) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Usage log failed', ['error' => $e->getMessage()]);
        }
    }
}
