<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\Setting;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;
use App\Services\PaperBroker\PaperBrokerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TickMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected TradingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = TradingAccount::create(['name' => 'Test', 'currency' => 'USD', 'initial_balance' => 10000, 'balance' => 10000, 'equity' => 10000, 'is_active' => true]);
        TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'is_enabled' => true, 'spread_pips' => 1.2, 'tick_size' => 0.00001, 'tick_value' => 1]);
    }

    private function openPosition(float $entry = 1.1000, float $sl = 1.0980, float $tp = 1.1030, float $vol = 0.10): PaperPosition
    {
        $order = PaperOrder::create(['trading_account_id' => $this->account->id, 'symbol' => 'EURUSD', 'order_type' => 'LIMIT_BUY', 'direction' => 'BUY', 'entry_price' => $entry, 'stop_loss' => $sl, 'take_profit' => $tp, 'volume' => $vol, 'risk_amount' => 20, 'status' => 'FILLED', 'fill_price' => $entry, 'filled_at' => now(), 'expires_at' => now()->addDay()]);
        return PaperPosition::create(['trading_account_id' => $this->account->id, 'paper_order_id' => $order->id, 'symbol' => 'EURUSD', 'direction' => 'BUY', 'entry_price' => $entry, 'volume' => $vol, 'stop_loss' => $sl, 'take_profit' => $tp, 'current_price' => $entry, 'peak_price' => $entry, 'status' => 'OPEN', 'opened_at' => now(), 'max_hold_until' => now()->addDay()]);
    }

    public function test_tick_fills_buy_limit_on_ask_and_uses_bid_for_stops(): void
    {
        $order = PaperOrder::create(['trading_account_id' => $this->account->id, 'symbol' => 'EURUSD', 'order_type' => 'LIMIT_BUY', 'direction' => 'BUY', 'entry_price' => 1.1000, 'stop_loss' => 1.0980, 'take_profit' => 1.1030, 'volume' => 0.10, 'risk_amount' => 20, 'status' => 'PENDING', 'expires_at' => now()->addHours(2)]);
        $broker = app(PaperBrokerService::class);
        // Ask still above entry → no fill.
        $this->assertSame('NO_FILL', $broker->processPendingOrderTicks('EURUSD', 1.1005, 1.1006)[0]['action']);
        // Ask touches entry → fill.
        $res = $broker->processPendingOrderTicks('EURUSD', 1.0999, 1.1000);
        $this->assertSame('FILLED', $res[0]['action']);
        $this->assertSame('OPEN', PaperPosition::where('paper_order_id', $order->id)->first()->status);
    }

    public function test_tick_stop_executes_on_bid_without_waiting_for_candle(): void
    {
        $pos = $this->openPosition();
        $out = app(PaperBrokerService::class)->monitorPositionTicks('EURUSD', 1.0979, 1.0980);
        $this->assertSame('CLOSED', $out[0]['action']);
        $this->assertSame('STOP_LOSS', $out[0]['reason']);
        $this->assertLessThan(0, $out[0]['pnl']);
    }

    public function test_breakeven_and_trailing_lock_profit(): void
    {
        // Risk distance 0.0020 ($20/lot at 0.10 → $2... use bigger excursion).
        $pos = $this->openPosition(1.1000, 1.0980, 1.1040, 0.10);
        $broker = app(PaperBrokerService::class);
        // +1R (bid 1.1020) → breakeven 1.1000, but trailing (peak - 0.5R) is
        // better at 1.1010, so protection jumps straight there.
        $broker->monitorPositionTicks('EURUSD', 1.1020, 1.1021);
        $this->assertEquals(1.1010, (float) $pos->fresh()->stop_loss);
        // Peak 1.1030 → trailing SL = peak - 0.5R(0.001) = 1.1020.
        $broker->monitorPositionTicks('EURUSD', 1.1030, 1.1031);
        $this->assertEquals(1.1020, (float) $pos->fresh()->stop_loss);
        // Pullback to 1.1015: hits trailed SL 1.1020? bid 1.1015 <= 1.1020 → STOP_LOSS with profit.
        $out = $broker->monitorPositionTicks('EURUSD', 1.1015, 1.1016);
        $this->assertSame('CLOSED', $out[0]['action']);
        $this->assertGreaterThan(0, $out[0]['pnl']);
    }

    public function test_protection_disabled_leaves_sl_alone(): void
    {
        Setting::put('protect.enabled', '', 'risk');
        $pos = $this->openPosition();
        app(PaperBrokerService::class)->monitorPositionTicks('EURUSD', 1.1020, 1.1021);
        $this->assertEquals(1.0980, (float) $pos->fresh()->stop_loss);
    }

    public function test_percentage_metrics(): void
    {
        $pos = $this->openPosition(1.1000, 1.0980, 1.1030, 0.10);
        $pos->update(['current_price' => 1.1010, 'unrealized_pnl' => 10.00]);
        // +0.0010 on 1.1000 = +0.0909% move; R: 10/20 = 0.5.
        $this->assertEquals(0.091, $pos->fresh()->movePct());
        $this->assertEquals(0.5, $pos->fresh()->rMultiple());
        $pos->update(['status' => 'CLOSED', 'exit_price' => 1.0990, 'realized_pnl' => -10.00, 'close_reason' => 'STOP_LOSS', 'closed_at' => now()]);
        $this->assertEquals(-0.091, $pos->fresh()->movePct());
    }
}
