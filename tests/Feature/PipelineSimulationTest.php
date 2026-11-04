<?php

namespace Tests\Feature;

use App\Agents\MarketDiscoveryAgent;
use App\Agents\TradeDecisionAgent;
use App\Jobs\AnalyzeClosedTradeJob;
use App\Models\AgentTask;
use App\Models\Candle;
use App\Models\PaperPosition;
use App\Models\SystemLog;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;
use App\Services\LLM\OpenRouterService;
use App\Services\MarketData\MarketDataService;
use App\Services\PaperBroker\PaperBrokerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeOpenRouterService;
use Tests\TestCase;

/**
 * Deterministic end-to-end pipeline simulation:
 * candles → snapshot → discovery (auto-TF) → trade decision → risk →
 * pending order → fill → TP close → post-trade analysis → metrics.
 * The LLM is stubbed (FakeOpenRouterService); everything else is production code.
 */
class PipelineSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance(OpenRouterService::class, new FakeOpenRouterService());
        TradingAccount::create(['name' => 'Sim', 'currency' => 'USD', 'initial_balance' => 10000, 'balance' => 10000, 'equity' => 10000, 'is_active' => true]);
        TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'is_enabled' => true, 'analysis_timeframe' => 'M1', 'spread_pips' => 1.2, 'tick_size' => 0.00001, 'tick_value' => 1]);
    }

    private function candle(string $time, float $o, float $h, float $l, float $c): Candle
    {
        return Candle::create(['symbol' => 'EURUSD', 'timeframe' => 'M1', 'candle_time' => $time, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'source' => 'sim']);
    }

    public function test_full_pipeline_simulation(): void
    {
        $md = app(MarketDataService::class);
        $broker = app(PaperBrokerService::class);

        // 1. Deterministic candle history (analysis TF pinned to M1 for the sim).
        $this->candle('2026-01-05 00:00:00', 1.1005, 1.1012, 1.0998, 1.1002);
        $this->candle('2026-01-05 00:01:00', 1.1002, 1.1008, 1.0996, 1.1000);
        $snap = $md->snapshot('EURUSD');
        $this->assertEquals('EURUSD', $snap->symbol);
        $this->assertEquals('M1', $snap->timeframe);

        // 2. Discovery agent ranks pairs AND auto-selects timeframe (RANGING → M15).
        $discovery = new MarketDiscoveryAgent(app(OpenRouterService::class));
        $task = AgentTask::create(['task_type' => 'discover_pairs', 'agent_name' => 'market_discovery', 'priority' => 9, 'status' => 'PENDING', 'input_context' => ['snapshots' => [$snap->toArray()]], 'scheduled_at' => now()]);
        $res = $discovery->run($task);
        $this->assertTrue($res['ok']);
        $this->assertSame('M15', TradingSymbol::where('symbol', 'EURUSD')->value('analysis_timeframe'));
        $this->assertSame('M15', MarketDataService::regimeTimeframe('RANGING'));
        $this->assertSame('H4', MarketDataService::regimeTimeframe('TRENDING'));
        $this->assertSame('H1', MarketDataService::regimeTimeframe('BOGUS'));

        // 3. Trade decision → risk validation → pending limit order.
        $decider = new TradeDecisionAgent(app(OpenRouterService::class));
        $dtask = AgentTask::create(['task_type' => 'evaluate_opportunity', 'agent_name' => 'trade_decision', 'symbol' => 'EURUSD', 'priority' => 8, 'status' => 'PENDING', 'input_context' => [], 'scheduled_at' => now()]);
        $this->assertTrue($decider->run($dtask)['ok']);
        $order = \App\Models\PaperOrder::where('symbol', 'EURUSD')->where('status', 'PENDING')->first();
        $this->assertNotNull($order);
        $this->assertEquals(0.12, (float) $order->volume);
        $this->assertEquals(24.00, (float) $order->risk_amount);

        // 4. Market reaches entry → fill.
        $fill = $broker->processPendingOrders('EURUSD', $this->candle('2026-01-05 00:02:00', 1.1010, 1.1015, 1.0995, 1.1005));
        $this->assertSame('FILLED', $fill[0]['action']);
        $position = PaperPosition::where('paper_order_id', $order->id)->first();
        $this->assertSame('OPEN', $position->status);

        // 5. Drift candle → HOLD with positive MTM.
        $hold = $broker->monitorPositions('EURUSD', $this->candle('2026-01-05 00:03:00', 1.1005, 1.1010, 1.1000, 1.1008));
        $this->assertSame('HOLD', $hold[0]['action']);
        $this->assertGreaterThan(0, (float) $hold[0]['unrealized']);

        // 6. TP touched (SL untouched) → profitable close.
        $close = $broker->monitorPositions('EURUSD', $this->candle('2026-01-05 00:04:00', 1.1008, 1.1035, 1.1005, 1.1030));
        $this->assertSame('CLOSED', $close[0]['action']);
        $this->assertSame('TAKE_PROFIT', $close[0]['reason']);
        $this->assertEquals(38.40, (float) $close[0]['pnl']);

        // 7. Post-trade analysis job (sync queue runs the stubbed agent inline).
        AnalyzeClosedTradeJob::dispatch($position->id);
        $this->assertDatabaseHas('trade_analyses', ['paper_position_id' => $position->id]);

        // 8. Metrics, costs, logs.
        $m = app(\App\Services\Learning\PerformanceService::class)->compute();
        $this->assertSame(1, $m['total_trades']);
        $this->assertSame(1, $m['wins']);
        $this->assertEquals(38.40, $m['net_profit']);
        $this->assertGreaterThan(0, \App\Models\ModelUsageLog::count());
        $this->assertGreaterThan(0, (float) \App\Models\ModelUsageLog::sum('estimated_cost'));
        $this->assertTrue(SystemLog::where('component', 'broker')->where('message', 'like', '%FILLED%')->exists());
        $this->assertTrue(SystemLog::where('component', 'broker')->where('message', 'like', '%CLOSED%')->exists());
        $this->assertEquals(10038.40, (float) TradingAccount::first()->balance);
    }
}
