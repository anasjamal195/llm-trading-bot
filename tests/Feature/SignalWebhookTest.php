<?php

namespace Tests\Feature;

use App\Models\PaperOrder;
use App\Models\Setting;
use App\Models\TradingAccount;
use App\Services\Notifications\SignalWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignalWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('webhook.enabled', '1', 'webhook');
        Setting::put('webhook.signals_url', 'https://graphichat.ai/webhook/signals', 'webhook');
        Setting::put('webhook.signals_secret', 'test-secret', 'webhook', true);
        TradingAccount::create(['name' => 'T', 'currency' => 'USD', 'initial_balance' => 10000, 'balance' => 10000, 'equity' => 10000, 'is_active' => true]);
        \App\Models\TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'is_enabled' => true]);
    }

    public function test_order_signal_posts_signed_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'id' => 7], 200)]);
        $order = PaperOrder::create(['trading_account_id' => TradingAccount::first()->id, 'symbol' => 'EURUSD', 'order_type' => 'LIMIT_BUY', 'direction' => 'BUY', 'entry_price' => 1.1000, 'stop_loss' => 1.0980, 'take_profit' => 1.1032, 'volume' => 0.10, 'risk_amount' => 20, 'status' => 'PENDING', 'expires_at' => now()->addHour()]);
        $this->assertTrue(app(SignalWebhookService::class)->sendOrderSignal($order));

        Http::assertSent(function ($req) {
            $sig = $req->header('X-Signature')[0] ?? '';
            $ts = $req->header('X-Timestamp')[0] ?? '';
            if (! str_starts_with($sig, 'sha256=') || $ts === '') return false;
            $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $req->body(), 'test-secret');
            if (! hash_equals($expected, $sig)) return false;
            $p = $req->data();
            return $p['event'] === 'signal.order_pending' && $p['symbol'] === 'EURUSD'
                && $p['entry'] == 1.1000 && $p['stop_loss'] == 1.0980 && $p['take_profit'] == 1.1032;
        });
    }

    public function test_disabled_webhook_skips_sending(): void
    {
        Setting::put('webhook.enabled', '', 'webhook');
        Http::fake();
        $order = PaperOrder::create(['trading_account_id' => TradingAccount::first()->id, 'symbol' => 'EURUSD', 'order_type' => 'LIMIT_BUY', 'direction' => 'BUY', 'entry_price' => 1.1000, 'stop_loss' => 1.0980, 'take_profit' => 1.1032, 'volume' => 0.10, 'status' => 'PENDING', 'expires_at' => now()->addHour()]);
        $this->assertFalse(app(SignalWebhookService::class)->sendOrderSignal($order));
        Http::assertNothingSent();
    }
}
