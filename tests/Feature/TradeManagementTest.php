<?php

namespace Tests\Feature;

use App\Jobs\AgentRunJob;
use App\Models\AgentTask;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\TradeAnalysis;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;
use App\Services\Trading\TradeManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TradingAccount::create(['name' => 'Test', 'currency' => 'USD', 'initial_balance' => 10000, 'balance' => 10000, 'equity' => 10000, 'is_active' => true]);
        TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'is_enabled' => true, 'spread_pips' => 1.2, 'tick_size' => 0.00001, 'tick_value' => 1]);
    }

    private function openPosition(): PaperPosition
    {
        $accountId = TradingAccount::first()->id;
        $order = PaperOrder::create(['trading_account_id' => $accountId, 'symbol' => 'EURUSD', 'order_type' => 'LIMIT_BUY', 'direction' => 'BUY', 'entry_price' => 1.1000, 'stop_loss' => 1.0980, 'take_profit' => 1.1030, 'volume' => 0.10, 'risk_amount' => 20, 'status' => 'FILLED', 'expires_at' => now()->addDay()]);
        return PaperPosition::create(['trading_account_id' => $accountId, 'paper_order_id' => $order->id, 'symbol' => 'EURUSD', 'direction' => 'BUY', 'entry_price' => 1.1000, 'volume' => 0.10, 'stop_loss' => 1.0980, 'take_profit' => 1.1030, 'current_price' => 1.1010, 'peak_price' => 1.1010, 'status' => 'OPEN', 'opened_at' => now(), 'max_hold_until' => now()->addHours(4)]);
    }

    public function test_early_close_blocked_before_expected_time(): void
    {
        $pos = $this->openPosition();
        $task = AgentTask::create(['task_type' => 'review_position', 'agent_name' => 'trade_management', 'symbol' => 'EURUSD', 'priority' => 7, 'status' => 'PENDING', 'input_context' => ['paper_position_id' => $pos->id], 'scheduled_at' => now()]);
        app(TradeManagementService::class)->applyRecommendation($task, ['recommendation' => 'CLOSE_EARLY']);
        $this->assertSame('OPEN', $pos->fresh()->status);
    }

    public function test_early_close_allowed_past_expected_time(): void
    {
        $pos = $this->openPosition();
        $pos->update(['max_hold_until' => now()->subMinute()]);
        $task = AgentTask::create(['task_type' => 'review_position', 'agent_name' => 'trade_management', 'symbol' => 'EURUSD', 'priority' => 7, 'status' => 'PENDING', 'input_context' => ['paper_position_id' => $pos->id], 'scheduled_at' => now()]);
        app(\App\Services\PaperBroker\PaperBrokerService::class)->closeEarly($pos->fresh(), 'MANUAL'); // ensure broker path works
        $this->assertSame('CLOSED', $pos->fresh()->status);
    }

    public function test_agent_close_past_expected_time_executes(): void
    {
        $pos = $this->openPosition();
        $pos->update(['max_hold_until' => now()->subMinute(), 'current_price' => 1.1010]);
        $task = AgentTask::create(['task_type' => 'review_position', 'agent_name' => 'trade_management', 'symbol' => 'EURUSD', 'priority' => 7, 'status' => 'PENDING', 'input_context' => ['paper_position_id' => $pos->id], 'scheduled_at' => now()]);
        app(TradeManagementService::class)->applyRecommendation($task, ['recommendation' => 'CLOSE_EARLY']);
        $this->assertSame('CLOSED', $pos->fresh()->status);
        $this->assertSame('AGENT_EARLY_EXIT', $pos->fresh()->close_reason);
    }

    public function test_tp_modification_rejected(): void
    {
        $pos = $this->openPosition();
        $task = AgentTask::create(['task_type' => 'review_position', 'agent_name' => 'trade_management', 'symbol' => 'EURUSD', 'priority' => 7, 'status' => 'PENDING', 'input_context' => ['paper_position_id' => $pos->id], 'scheduled_at' => now()]);
        app(TradeManagementService::class)->applyRecommendation($task, ['recommendation' => 'MODIFY_SL', 'new_sl' => 1.0990, 'new_tp' => 1.1100]);
        $this->assertEquals(1.1030, (float) $pos->fresh()->take_profit);
    }

    public function test_enrich_attaches_recent_lessons(): void
    {
        $pos = $this->openPosition();
        $pos->update(['status' => 'CLOSED', 'exit_price' => 1.0990, 'realized_pnl' => -10, 'close_reason' => 'STOP_LOSS', 'closed_at' => now(), 'strategy_code' => 'RANGE_REVERSAL']);
        TradeAnalysis::create(['paper_position_id' => $pos->id, 'prediction_summary' => 'p', 'outcome_summary' => 'o', 'entry_quality' => 'POOR', 'exit_quality' => 'FAIR', 'lessons' => 'Never chase into resistance']);
        $job = new AgentRunJob(0);
        $m = new \ReflectionMethod(AgentRunJob::class, 'enrich');
        $m->setAccessible(true);
        $task = AgentTask::create(['task_type' => 'evaluate_opportunity', 'agent_name' => 'trade_decision', 'symbol' => 'EURUSD', 'priority' => 8, 'status' => 'PENDING', 'input_context' => [], 'scheduled_at' => now()]);
        $ctx = $m->invoke($job, $task);
        $this->assertNotEmpty($ctx['recent_lessons']);
        $this->assertSame('Never chase into resistance', $ctx['recent_lessons'][0]['lessons']);
    }
}
