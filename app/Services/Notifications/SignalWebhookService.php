<?php

namespace App\Services\Notifications;

use App\Models\PaperOrder;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Outbound signal webhook fired when a limit order is approved (PENDING).
 * HMAC-signed JSON POST to the Graphichat receiver. Never blocks trading:
 * delivery runs on the notifications queue with retries.
 */
class SignalWebhookService
{
    public function __construct(private \App\Services\System\AppLogger $log) {}

    public function enabled(): bool
    {
        return (bool) Setting::get('webhook.enabled', '');
    }

    public function endpoint(): string
    {
        return (string) Setting::get('webhook.signals_url', 'https://graphichat.ai/webhook/signals');
    }

    public function sign(string $rawBody, string $timestamp): ?string
    {
        $secret = (string) Setting::get('webhook.signals_secret', '');
        if ($secret === '') return null;
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /** Canonical payload for a pending limit order. */
    public function orderPayload(PaperOrder $order, string $origin = 'live', bool $test = false): array
    {
        $order->loadMissing('tradeProposal');
        return [
            'event' => $test ? 'signal.test' : 'signal.order_pending',
            'origin' => $origin,
            'test' => $test,
            'order_id' => $order->id,
            'symbol' => $order->symbol,
            'direction' => $order->direction,
            'order_type' => $order->order_type,
            'entry' => (float) $order->entry_price,
            'stop_loss' => (float) $order->stop_loss,
            'take_profit' => (float) $order->take_profit,
            'volume' => (float) $order->volume,
            'risk_amount' => (float) $order->risk_amount,
            'risk_reward' => (float) ($order->tradeProposal?->risk_reward ?? 0),
            'strategy' => $order->tradeProposal?->strategy_code,
            'confidence' => $order->tradeProposal?->confidence !== null ? (float) $order->tradeProposal->confidence : null,
            'probability' => $order->tradeProposal?->probability !== null ? (float) $order->tradeProposal->probability : null,
            'rationale' => $order->tradeProposal?->rationale,
            'invalidation' => $order->tradeProposal?->invalidation,
            'expires_at' => $order->expires_at?->toIso8601String(),
            'account_currency' => $order->tradingAccount?->currency ?? 'USD',
            'bot' => (string) config('app.name'),
            'sent_at' => now()->toIso8601String(),
        ];
    }

    public function send(array $payload, bool $force = false): bool
    {
        if (! $force && ! $this->enabled()) {
            $this->log->info('webhook', 'Signal webhook skipped (disabled)');
            return false;
        }
        $url = $this->endpoint();
        if ($url === '') {
            $this->log->warning('webhook', 'Signal webhook has no URL configured');
            return false;
        }
        $timestamp = (string) time();
        $rawBody = json_encode($payload);
        $signature = $this->sign($rawBody, $timestamp);
        $headers = ['X-Timestamp' => $timestamp, 'X-Event' => $payload['event'] ?? 'signal.order_pending'];
        if ($signature) $headers['X-Signature'] = $signature;

        try {
            $res = Http::timeout((int) Setting::get('webhook.timeout', 10))->acceptJson()
                ->withHeaders($headers)->withBody($rawBody, 'application/json')->post($url);
            if ($res->successful()) {
                $this->log->info('webhook', "Signal delivered to {$url}", ['event' => $payload['event'] ?? null, 'symbol' => $payload['symbol'] ?? null, 'status' => $res->status()]);
                return true;
            }
            $this->log->warning('webhook', "Signal delivery failed HTTP {$res->status()}", ['body' => substr($res->body(), 0, 300)]);
            return false;
        } catch (\Throwable $e) {
            $this->log->error('webhook', 'Signal delivery exception', ['error' => substr($e->getMessage(), 0, 300)]);
            return false;
        }
    }

    public function sendOrderSignal(PaperOrder $order, string $origin = 'live', bool $test = false, bool $force = false): bool
    {
        return $this->send($this->orderPayload($order->fresh() ?? $order, $origin, $test), $force);
    }
}
