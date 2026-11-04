<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;
use App\Services\PaperBroker\PaperBrokerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperBrokerTest extends TestCase
{
    use RefreshDatabase;

    protected TradingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = TradingAccount::create(['name' => 'Test', 'currency' => 'USD', 'initial_balance' => 10000, 'balance' => 10000, 'equity' => 10000]);
        TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'is_enabled' => true, 'spread_pips' => 1.2, 'tick_size' => 0.00001, 'tick_value' => 1]);
    }

    private int $candleSeq = 0;

    private function candle(float $o, float $h, float $l, float $c): Candle
    {
        // Unique timestamp per candle (minute-spaced) to satisfy the unique constraint.
        $time = now()->subMinutes(30 - ($this->candleSeq++ % 30))->second(0);
        return Candle::create(['symbol' => 'EURUSD', 'timeframe' => 'M1', 'candle_time' => $time, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'source' => 'test']);
    }

    private function order(string $dir = 'BUY', float $entry = 1.1000): PaperOrder
    {
        return PaperOrder::create([
            'trading_account_id' => $this->account->id, 'symbol' => 'EURUSD',
            'order_type' => $dir === 'BUY' ? 'LIMIT_BUY' : 'LIMIT_SELL', 'direction' => $dir,
            'entry_price' => $entry, 'stop_loss' => $dir === 'BUY' ? $entry - 0.002 : $entry + 0.002,
            'take_profit' => $dir === 'BUY' ? $entry + 0.003 : $entry - 0.003,
            'volume' => 0.10, 'risk_amount' => 20, 'status' => 'PENDING', 'expires_at' => now()->addHours(2),
        ]);
    }

    public function test_buy_limit_fills_when_low_touches_entry(): void
    {
        $order = $this->order('BUY');
        $res = app(PaperBrokerService::class)->processPendingOrders('EURUSD', $this->candle(1.1020, 1.1025, 1.0995, 1.1015));
        $this->assertSame('FILLED', $res[0]['action']);
        $this->assertDatabaseHas('paper_positions', ['paper_order_id' => $order->id, 'status' => 'OPEN']);
    }

    public function test_no_fill_when_candle_misses_entry(): void
    {
        $order = $this->order('BUY');
        $res = app(PaperBrokerService::class)->processPendingOrders('EURUSD', $this->candle(1.1050, 1.1060, 1.1040, 1.1055));
        $this->assertSame('NO_FILL', $res[0]['action']);
        $this->assertSame('PENDING', $order->fresh()->status);
    }

    public function test_take_profit_closes_position(): void
    {
        $order = $this->order('BUY', 1.1000);
        $broker = app(PaperBrokerService::class);
        $broker->processPendingOrders('EURUSD', $this->candle(1.1020, 1.1025, 1.0995, 1.1015));
        $pos = PaperPosition::where('paper_order_id', $order->id)->first();
        $out = $broker->monitorPositions('EURUSD', $this->candle(1.1020, 1.1040, 1.1010, 1.1035));
        $this->assertSame('CLOSED', $out[0]['action']);
        $this->assertSame('TAKE_PROFIT', $out[0]['reason']);
        $this->assertGreaterThan(0, $out[0]['pnl']);
    }

    public function test_ambiguous_sl_tp_prefers_stop_loss(): void
    {
        $order = $this->order('BUY', 1.1000);
        $broker = app(PaperBrokerService::class);
        $broker->processPendingOrders('EURUSD', $this->candle(1.1020, 1.1025, 1.0995, 1.1015));
        // Candle touches both SL (1.0980) and TP (1.1030).
        $out = $broker->monitorPositions('EURUSD', $this->candle(1.1000, 1.1040, 1.0970, 1.1010));
        $this->assertSame('STOP_LOSS', $out[0]['reason']);
        $this->assertTrue((bool) $out[0]['ambiguous']);
    }

    public function test_duplicate_fill_and_close_are_idempotent(): void
    {
        $order = $this->order('BUY');
        $broker = app(PaperBrokerService::class);
        $c = $this->candle(1.1020, 1.1025, 1.0995, 1.1015);
        $broker->processPendingOrders('EURUSD', $c);
        $again = $broker->processPendingOrders('EURUSD', $c);
        $this->assertTrue($again === [] || ($again[0]['action'] ?? null) === 'SKIPPED');
        $this->assertSame(1, PaperPosition::where('paper_order_id', $order->id)->count());

        $pos = PaperPosition::where('paper_order_id', $order->id)->first();
        $broker->closeEarly($pos, 'MANUAL');
        $second = $broker->closeEarly($pos->fresh(), 'MANUAL');
        $this->assertSame('SKIPPED', $second['action']);
    }

    public function test_expired_orders_are_marked(): void
    {
        $order = $this->order();
        $order->update(['expires_at' => now()->subMinute()]);
        $n = app(PaperBrokerService::class)->expireDueOrders();
        $this->assertSame(1, $n);
        $this->assertSame('EXPIRED', $order->fresh()->status);
    }

    public function test_max_hold_closes_position(): void
    {
        $order = $this->order('BUY');
        $broker = app(PaperBrokerService::class);
        $broker->processPendingOrders('EURUSD', $this->candle(1.1020, 1.1025, 1.0995, 1.1015));
        $pos = PaperPosition::where('paper_order_id', $order->id)->first();
        $pos->update(['max_hold_until' => now()->subMinute()]);
        // Candle touches neither SL nor TP.
        $out = $broker->evaluatePosition($pos->fresh(), $this->candle(1.1005, 1.1010, 1.0995, 1.1008));
        $this->assertSame('CLOSED', $out['action']);
        $this->assertSame('MAX_HOLD', $out['reason']);
    }
}
