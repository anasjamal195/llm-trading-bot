<?php

namespace App\Console\Commands;

use App\Jobs\SendSignalWebhookJob;
use App\Models\PaperOrder;
use App\Models\Setting;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;
use App\Services\MarketData\MarketDataService;
use App\Services\Notifications\SignalWebhookService;
use Illuminate\Console\Command;

/**
 * Fire a signal webhook on demand for any symbol.
 * Default: synthetic test payload (no DB writes), clearly marked test:true.
 * --real: creates a genuine far-from-market PENDING paper order (visible in
 * Pending Orders; cancel it from the admin panel afterwards).
 */
class TestWebhook extends Command
{
    protected $signature = 'bot:test-webhook {symbol=EURUSD} {--direction=BUY} {--real}';
    protected $description = 'Send a test signal webhook (synthetic) or a real pending-order signal';

    public function handle(SignalWebhookService $webhook, MarketDataService $md): int
    {
        $symbol = strtoupper($this->argument('symbol'));
        $direction = strtoupper($this->option('direction') === 'SELL' ? 'SELL' : 'BUY');
        $this->info('Endpoint: ' . $webhook->endpoint());
        $this->info('Enabled: ' . ($webhook->enabled() ? 'yes' : 'no (use --force path? no: enable in Settings → webhook.enabled)'));

        if (! $this->option('real')) {
            try {
                $snap = $md->snapshot($symbol);
                $ref = (float) $snap->price;
            } catch (\Throwable) {
                $c = \App\Models\Candle::where('symbol', $symbol)->orderBy('candle_time', 'desc')->first();
                if (! $c) { $this->error("No market data for {$symbol}"); return 1; }
                $ref = (float) $c->close;
            }
            $entry = $ref;
            $sl = $direction === 'BUY' ? $ref * 0.998 : $ref * 1.002;
            $tp = $direction === 'BUY' ? $ref * 1.003 : $ref * 0.997;
            $payload = [
                'event' => 'signal.test', 'origin' => 'manual_test', 'test' => true,
                'order_id' => null, 'symbol' => $symbol, 'direction' => $direction,
                'order_type' => $direction === 'BUY' ? 'LIMIT_BUY' : 'LIMIT_SELL',
                'entry' => round($entry, 5), 'stop_loss' => round($sl, 5), 'take_profit' => round($tp, 5),
                'volume' => 0.01, 'risk_amount' => 0, 'risk_reward' => 1.5,
                'strategy' => 'MANUAL_TEST', 'confidence' => 1.0, 'probability' => 1.0,
                'rationale' => 'Manual webhook connectivity test', 'invalidation' => null,
                'expires_at' => now()->addHour()->toIso8601String(),
                'account_currency' => 'USD', 'bot' => (string) config('app.name'),
                'sent_at' => now()->toIso8601String(),
            ];
            $ok = $webhook->send($payload, true);
            $this->info($ok ? 'Test signal sent.' : 'Test signal NOT sent (see System Logs → webhook).');
            return $ok ? 0 : 1;
        }

        $ts = TradingSymbol::where('symbol', $symbol)->first();
        $account = TradingAccount::where('is_active', true)->first();
        if (! $ts || ! $ts->is_enabled) { $this->error("Symbol {$symbol} not enabled"); return 1; }
        if (! $account) { $this->error('No active trading account'); return 1; }
        $ref = (float) (\App\Models\Candle::where('symbol', $symbol)->orderBy('candle_time', 'desc')->value('close') ?? 0);
        if ($ref <= 0) { $this->error("No candles for {$symbol}"); return 1; }
        // Far from market so the test order never fills on its own.
        $entry = $direction === 'BUY' ? $ref * 0.95 : $ref * 1.05;
        $sl = $direction === 'BUY' ? $entry * 0.997 : $entry * 1.003;
        $tp = $direction === 'BUY' ? $entry * 1.0045 : $entry * 0.9955;
        $order = PaperOrder::create([
            'trading_account_id' => $account->id, 'symbol' => $symbol,
            'order_type' => $direction === 'BUY' ? 'LIMIT_BUY' : 'LIMIT_SELL', 'direction' => $direction,
            'entry_price' => round($entry, 5), 'stop_loss' => round($sl, 5), 'take_profit' => round($tp, 5),
            'volume' => 0.01, 'risk_amount' => 0, 'status' => 'PENDING',
            'expires_at' => now()->addMinutes(30), 'created_by' => 'manual_test',
        ]);
        SendSignalWebhookJob::dispatch($order->id, 'manual_test')->onQueue('notifications');
        $this->info("Real PENDING order #{$order->id} created; signal job queued. Cancel it in Pending Orders when done.");
        return 0;
    }
}
