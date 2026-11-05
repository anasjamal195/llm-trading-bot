<?php

namespace Tests\Feature;

use App\Agents\MarketDiscoveryAgent;
use App\Models\AgentTask;
use App\Models\Setting;
use App\Services\LLM\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeOpenRouterService;
use Tests\TestCase;

class LlmRobustnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_json_gets_one_retry(): void
    {
        $fake = new class extends FakeOpenRouterService {
            public int $calls = 0;
            public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
            {
                $this->calls++;
                if ($this->calls === 1) return ['ok' => false, 'error' => 'Invalid JSON from model'];
                return ['ok' => true, 'data' => ['rankings' => [], 'next_review_minutes' => 30], 'usage' => []];
            }
        };
        $task = AgentTask::create(['task_type' => 'discover_pairs', 'agent_name' => 'market_discovery', 'priority' => 9, 'status' => 'PENDING', 'input_context' => [], 'scheduled_at' => now()]);
        $this->assertTrue((new MarketDiscoveryAgent($fake))->run($task->fresh())['ok']);
        $this->assertSame(2, $fake->calls);
        $this->assertSame('COMPLETED', $task->fresh()->status);
    }

    public function test_other_errors_do_not_retry(): void
    {
        $fake = new class extends FakeOpenRouterService {
            public int $calls = 0;
            public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
            {
                $this->calls++;
                return ['ok' => false, 'error' => 'HTTP 401: bad key'];
            }
        };
        $task = AgentTask::create(['task_type' => 'discover_pairs', 'agent_name' => 'market_discovery', 'priority' => 9, 'status' => 'PENDING', 'input_context' => [], 'scheduled_at' => now()]);
        $this->assertFalse((new MarketDiscoveryAgent($fake))->run($task->fresh())['ok']);
        $this->assertSame(1, $fake->calls);
    }

    public function test_per_agent_max_tokens_and_pricing(): void
    {
        Setting::put('openrouter.api_key', 'test-key', 'openrouter');
        Setting::put('openrouter.pricing', '{"deepseek/deepseek-v3.2":{"in":0.26,"out":0.38}}', 'openrouter');
        Setting::put('openrouter.max_tokens.chief_trading', '2500', 'openrouter');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"actions":[],"next_review_minutes":30}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])]);
        $svc = app(OpenRouterService::class);
        $res = $svc->chatJson('chief_trading', 'sys', ['a' => 1]);
        $this->assertTrue($res['ok']);
        Http::assertSent(fn ($req) => ($req->data()['max_tokens'] ?? null) === 2500 && ($req->data()['model'] ?? '') !== '');
        $this->assertSame(['in' => 0.26, 'out' => 0.38], $svc->priceFor('deepseek/deepseek-v3.2'));
    }
}
