<?php

namespace Tests\Feature;

use App\Agents\NewsResearchAgent;
use App\Models\AgentTask;
use App\Models\NewsArticle;
use App\Services\LLM\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeOpenRouterService;
use Tests\TestCase;

class NewsPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function agentWith(array $articles): NewsResearchAgent
    {
        $fake = new class($articles) extends FakeOpenRouterService {
            public function __construct(private array $articles) {}
            public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
            {
                return ['ok' => true, 'data' => ['articles' => $this->articles, 'next_review_minutes' => 30], 'usage' => []];
            }
        };
        app()->instance(OpenRouterService::class, $fake);
        return new NewsResearchAgent($fake);
    }

    public function test_empty_headlines_yield_no_articles(): void
    {
        $task = AgentTask::create(['task_type' => 'research_news', 'agent_name' => 'news_research', 'priority' => 6, 'status' => 'PENDING', 'input_context' => ['headlines' => []], 'scheduled_at' => now()]);
        $this->assertTrue($this->agentWith([])->run($task)['ok']);
        $this->assertSame(0, NewsArticle::count());
    }

    public function test_only_dated_fresh_items_persist_with_dedupe(): void
    {
        $fresh = ['headline' => 'ECB holds rates steady', 'source' => 'TestWire', 'published_at' => now()->subHour()->toIso8601String(), 'currency' => 'EUR', 'event_category' => 'CENTRAL_BANK', 'impact' => 'HIGH', 'sentiment' => 'NEUTRAL', 'confidence' => 0.9];
        $articles = [
            $fresh,
            $fresh, // duplicate
            ['headline' => 'No date item', 'source' => 'X'], // no date → dropped
            ['headline' => 'Ancient news', 'source' => 'X', 'published_at' => now()->subDays(30)->toIso8601String()], // stale → dropped
        ];
        $task = AgentTask::create(['task_type' => 'research_news', 'agent_name' => 'news_research', 'priority' => 6, 'status' => 'PENDING', 'input_context' => ['headlines' => [$fresh]], 'scheduled_at' => now()]);
        $this->assertTrue($this->agentWith($articles)->run($task)['ok']);
        $this->assertSame(1, NewsArticle::count());
        $this->assertSame('ECB holds rates steady', NewsArticle::first()->headline);
    }
}
