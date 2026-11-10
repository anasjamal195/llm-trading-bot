<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\Setting;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;
use App\Services\MT5\CandleProvider;
use App\Services\Risk\RiskManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskAndMarketTest extends TestCase
{
    use RefreshDatabase;

    protected TradingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = TradingAccount::create(['name' => 'Test', 'currency' => 'USD', 'initial_balance' => 10000, 'balance' => 10000, 'equity' => 10000]);
        TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'is_enabled' => true, 'spread_pips' => 1.2, 'tick_size' => 0.00001, 'tick_value' => 1]);
    }

    private function proposal(array $over = []): array
    {
        return array_merge(['symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'LIMIT_BUY', 'entry' => 1.1000, 'stop_loss' => 1.0980, 'take_profit' => 1.1035], $over);
    }

    public function test_valid_proposal_approved_with_volume(): void
    {
        $res = app(RiskManagementService::class)->validateProposal($this->proposal());
        $this->assertTrue($res['approved'], json_encode($res['reasons']));
        $this->assertGreaterThan(0, $res['volume']);
    }

    public function test_rejects_bad_risk_reward(): void
    {
        $res = app(RiskManagementService::class)->validateProposal($this->proposal(['take_profit' => 1.1005]));
        $this->assertFalse($res['approved']);
    }

    public function test_rejects_invalid_sl_side(): void
    {
        $res = app(RiskManagementService::class)->validateProposal($this->proposal(['stop_loss' => 1.1020]));
        $this->assertFalse($res['approved']);
    }

    public function test_kill_switch_blocks_everything(): void
    {
        Setting::put('risk.kill_switch', '1', 'risk');
        $res = app(RiskManagementService::class)->validateProposal($this->proposal());
        $this->assertFalse($res['approved']);
    }

    public function test_max_open_positions_blocks(): void
    {
        Setting::put('risk.max_open_positions', '1', 'risk');
        $order = \App\Models\PaperOrder::create(['trading_account_id' => $this->account->id, 'symbol' => 'EURUSD', 'order_type' => 'LIMIT_BUY', 'direction' => 'BUY', 'entry_price' => 1.1, 'stop_loss' => 1.09, 'take_profit' => 1.11, 'volume' => 0.1, 'status' => 'FILLED', 'expires_at' => now()->addDay()]);
        PaperPosition::create(['trading_account_id' => $this->account->id, 'paper_order_id' => $order->id, 'symbol' => 'EURUSD', 'direction' => 'BUY', 'entry_price' => 1.1, 'volume' => 0.1, 'stop_loss' => 1.09, 'take_profit' => 1.11, 'status' => 'OPEN', 'max_hold_until' => now()->addDay()]);
        $res = app(RiskManagementService::class)->validateProposal($this->proposal());
        $this->assertFalse($res['approved']);
    }

    public function test_consecutive_losses_block(): void
    {
        Setting::put('risk.max_consecutive_losses', '2', 'risk');
        $order = \App\Models\PaperOrder::create(['trading_account_id' => $this->account->id, 'symbol' => 'EURUSD', 'order_type' => 'LIMIT_BUY', 'direction' => 'BUY', 'entry_price' => 1.1, 'stop_loss' => 1.09, 'take_profit' => 1.11, 'volume' => 0.1, 'status' => 'FILLED', 'expires_at' => now()->addDay()]);
        foreach ([-10, -5] as $pnl) {
            PaperPosition::create(['trading_account_id' => $this->account->id, 'paper_order_id' => $order->id, 'symbol' => 'EURUSD', 'direction' => 'BUY', 'entry_price' => 1.1, 'volume' => 0.1, 'stop_loss' => 1.09, 'take_profit' => 1.11, 'status' => 'CLOSED', 'realized_pnl' => $pnl, 'closed_at' => now(), 'max_hold_until' => now()->addDay()]);
        }
        $res = app(RiskManagementService::class)->validateProposal($this->proposal());
        $this->assertFalse($res['approved']);
    }

    public function test_candle_validation(): void
    {
        $this->assertTrue(CandleProvider::isValidOhlc(['open' => 1, 'high' => 2, 'low' => 0.5, 'close' => 1.5]));
        $this->assertFalse(CandleProvider::isValidOhlc(['open' => 1, 'high' => 0.5, 'low' => 2, 'close' => 1.5]));
        $this->assertFalse(CandleProvider::isValidOhlc(['open' => 0, 'high' => 2, 'low' => 0.5, 'close' => 1.5]));
    }

    public function test_gold_pip_and_volume_math(): void
    {
        \App\Models\TradingSymbol::create(['symbol' => 'XAUUSD', 'base_currency' => 'XAU', 'quote_currency' => 'USD', 'is_enabled' => true, 'pip_size' => 0.1, 'spread_pips' => 2.0, 'tick_size' => 0.01, 'tick_value' => 1.0, 'contract_size' => 100]);
        $risk = app(RiskManagementService::class);
        $this->assertSame(0.1, $risk->pipSize('XAUUSD'));
        // $5 stop distance on gold: 500 ticks × $1 = $500/lot; $25 risk → 0.05 lots.
        $this->assertEquals(0.05, $risk->volumeForRisk(\App\Models\TradingSymbol::where('symbol', 'XAUUSD')->first(), 5.00, 25.00));
        // P&L: $3.20 move × 100oz × 0.05 lots = $16.00.
        $this->assertEquals(16.00, app(\App\Services\PaperBroker\PaperBrokerService::class)->pnl('XAUUSD', 'BUY', 4061.10, 4064.30, 0.05));
        // Spread guard uses metal pip size (2 pips = $0.20, well under 25% of a $5 stop).
        $res = $risk->validateProposal(['symbol' => 'XAUUSD', 'direction' => 'BUY', 'order_type' => 'LIMIT_BUY', 'entry' => 4061.10, 'stop_loss' => 4056.10, 'take_profit' => 4068.60]);
        $this->assertTrue($res['approved'], json_encode($res['reasons']));
    }

    public function test_interval_mapping(): void
    {
        $p = new CandleProvider('http://x', '', 5);
        $this->assertSame('1m', $p->toBridgeInterval('M1'));
        $this->assertSame('1h', $p->toBridgeInterval('H1'));
        $this->assertSame('4h', $p->toBridgeInterval('H4'));
        $this->expectException(\InvalidArgumentException::class);
        $p->toBridgeInterval('XYZ');
    }

    public function test_rejects_take_profit_below_minimum_pct(): void
    {
        // 0.10% TP on a 0.15% floor → reject.
        $res = app(RiskManagementService::class)->validateProposal($this->proposal(['take_profit' => 1.1011]));
        $this->assertFalse($res['approved']);
        $this->assertStringContainsString('0.15%', implode(';', $res['reasons']));
    }

    public function test_rejects_low_confidence(): void
    {
        $res = app(RiskManagementService::class)->validateProposal($this->proposal(['confidence' => 0.4]));
        $this->assertFalse($res['approved']);
    }

    public function test_accepts_profitable_confident_proposal(): void
    {
        // TP 1.1035 on 1.1000 = 0.318% ≥ 0.15%, default fixture has no confidence → gate skipped.
        $res = app(RiskManagementService::class)->validateProposal($this->proposal(['confidence' => 0.7]));
        $this->assertTrue($res['approved'], json_encode($res['reasons']));
    }

    public function test_xaujpy_pnl_converts_through_usdjpy(): void
    {
        \App\Models\TradingSymbol::create(['symbol' => 'XAUJPY', 'base_currency' => 'XAU', 'quote_currency' => 'JPY', 'is_enabled' => true, 'pip_size' => 100, 'spread_pips' => 1.2, 'tick_size' => 1.0, 'tick_value' => 100.0, 'contract_size' => 100]);
        \App\Models\Candle::create(['symbol' => 'USDJPY', 'timeframe' => 'M1', 'candle_time' => now()->subMinute(), 'open' => 154, 'high' => 154, 'low' => 154, 'close' => 154, 'source' => 'test']);
        // BUY 638000 → 638500 (500 ticks × JPY100 × 0.01 lot = JPY500 → /154).
        $this->assertEquals(3.25, app(\App\Services\PaperBroker\PaperBrokerService::class)->pnl('XAUJPY', 'BUY', 638000, 638500, 0.01));
        // Risk sizing honours conversion: JPY2000 SL at $25 risk → 0.01 lots.
        $this->assertEquals(0.01, app(RiskManagementService::class)->volumeForRisk(\App\Models\TradingSymbol::where('symbol', 'XAUJPY')->first(), 2000, 25.00));
        // USD-quoted pairs are unaffected (rate 1.0).
        $this->assertEquals(1.0, \App\Services\PaperBroker\PaperBrokerService::quoteToUsd('EURUSD'));
    }

    public function test_agent_schema_validation(): void
    {
        $agent = app(\App\Agents\TradeDecisionAgent::class);
        $this->assertSame([true, ''], $agent->validate(['decision' => 'NO_TRADE']));
        [$ok] = $agent->validate(['decision' => 'PROPOSE', 'symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'LIMIT_BUY', 'entry' => 1.1, 'stop_loss' => 1.09, 'take_profit' => 1.12]);
        $this->assertTrue($ok);
        [$ok2] = $agent->validate(['decision' => 'PROPOSE', 'symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'MARKET', 'entry' => 1.1, 'stop_loss' => 1.09, 'take_profit' => 1.12]);
        $this->assertFalse($ok2);
    }
}
