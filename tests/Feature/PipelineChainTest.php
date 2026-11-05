<?php

namespace Tests\Feature;

use App\Agents\MarketAnalysisAgent;
use App\Agents\StrategySelectionAgent;
use App\Models\AgentTask;
use App\Models\PairRanking;
use App\Models\TradingSymbol;
use App\Services\LLM\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeOpenRouterService;
use Tests\TestCase;

class PipelineChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance(OpenRouterService::class, new FakeOpenRouterService());
        TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'is_enabled' => true]);
    }

    public function test_analysis_chains_to_strategy_when_pair_suitable(): void
    {
        PairRanking::create(['symbol' => 'EURUSD', 'score' => 80, 'suitable' => true, 'regime' => 'RANGING']);
        $task = AgentTask::create(['task_type' => 'analyze_pair', 'agent_name' => 'market_analysis', 'symbol' => 'EURUSD', 'priority' => 5, 'status' => 'PENDING', 'input_context' => [], 'scheduled_at' => now()]);
        // Fake returns canned analysis (MarketAnalysisAgent has no canned branch → default ok payload lacks symbol/trend → fails validation).
        // Provide a tailored fake instead.
        $fake = new class extends FakeOpenRouterService {
            public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
            {
                return ['ok' => true, 'data' => ['symbol' => 'EURUSD', 'trend' => 'RANGE', 'next_review_minutes' => 15], 'usage' => []];
            }
        };
        app()->instance(OpenRouterService::class, $fake);
        $this->assertTrue((new MarketAnalysisAgent($fake))->run($task->fresh())['ok']);
        $this->assertDatabaseHas('agent_tasks', ['agent_name' => 'strategy_selection', 'symbol' => 'EURUSD', 'status' => 'PENDING']);
    }

    public function test_analysis_skips_chain_when_pair_not_suitable(): void
    {
        PairRanking::create(['symbol' => 'EURUSD', 'score' => 10, 'suitable' => false, 'regime' => 'VOLATILE']);
        $fake = new class extends FakeOpenRouterService {
            public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
            {
                return ['ok' => true, 'data' => ['symbol' => 'EURUSD', 'trend' => 'RANGE', 'next_review_minutes' => 15], 'usage' => []];
            }
        };
        $task = AgentTask::create(['task_type' => 'analyze_pair', 'agent_name' => 'market_analysis', 'symbol' => 'EURUSD', 'priority' => 5, 'status' => 'PENDING', 'input_context' => [], 'scheduled_at' => now()]);
        $this->assertTrue((new MarketAnalysisAgent($fake))->run($task->fresh())['ok']);
        $this->assertSame(0, AgentTask::where('agent_name', 'strategy_selection')->count());
    }

    public function test_strategy_chains_to_decision_unless_no_trade(): void
    {
        $fakeYes = new class extends FakeOpenRouterService {
            public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
            {
                return ['ok' => true, 'data' => ['strategy' => 'MOMENTUM_BREAKOUT', 'regime' => 'VOLATILE', 'reason' => 'sim', 'next_review_minutes' => 15], 'usage' => []];
            }
        };
        $task = AgentTask::create(['task_type' => 'select_strategy', 'agent_name' => 'strategy_selection', 'symbol' => 'EURUSD', 'priority' => 7, 'status' => 'PENDING', 'input_context' => [], 'scheduled_at' => now()]);
        $this->assertTrue((new StrategySelectionAgent($fakeYes))->run($task->fresh())['ok']);
        $this->assertDatabaseHas('agent_tasks', ['agent_name' => 'trade_decision', 'symbol' => 'EURUSD', 'status' => 'PENDING']);

        $fakeNo = new class extends FakeOpenRouterService {
            public function chatJson(string $agent, string $system, array|string $user, array $opts = []): array
            {
                return ['ok' => true, 'data' => ['strategy' => 'NO_TRADE', 'regime' => 'UNKNOWN', 'reason' => 'sim chop', 'next_review_minutes' => 30], 'usage' => []];
            }
        };
        $before = AgentTask::where('agent_name', 'trade_decision')->count();
        $task2 = AgentTask::create(['task_type' => 'select_strategy', 'agent_name' => 'strategy_selection', 'symbol' => 'EURUSD', 'priority' => 7, 'status' => 'PENDING', 'input_context' => [], 'scheduled_at' => now()]);
        $this->assertTrue((new StrategySelectionAgent($fakeNo))->run($task2->fresh())['ok']);
        $this->assertSame($before, AgentTask::where('agent_name', 'trade_decision')->count());
    }
}
