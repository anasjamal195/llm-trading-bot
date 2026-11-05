<?php

namespace App\Jobs;

use App\Models\PaperOrder;
use App\Services\Notifications\SignalWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers the pending-order signal webhook off the critical trading path.
 * Retried with backoff; a failing receiver must never block the paper broker.
 */
class SendSignalWebhookJob implements ShouldQueue
{
    use Queueable;
    public $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(public int $orderId, public string $origin = 'live') {}

    public function handle(SignalWebhookService $webhook): void
    {
        $order = PaperOrder::find($this->orderId);
        if (! $order || $order->status !== 'PENDING') return;
        if (! $webhook->enabled()) return; // nothing configured: silently skip
        if (! $webhook->sendOrderSignal($order, $this->origin)) {
            throw new \RuntimeException('Signal webhook delivery failed for order #' . $this->orderId);
        }
    }
}
