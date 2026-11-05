<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// All broadcast events: queued, private admin channels, minimal payloads (no secrets).

trait BroadcastsAdmin
{
    use Dispatchable, SerializesModels;
    public function broadcastQueue(): string { return 'notifications'; }
}

class BotStatusChanged implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public string $status, public ?string $message = null) {}
    public function broadcastOn(): array { return [new PrivateChannel('bot.status')]; }
    public function broadcastWith(): array { return ['status' => $this->status, 'message' => $this->message, 'at' => now()->toIso8601String()]; }
}

class MarketSnapshotUpdated implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public array $snapshot) {}
    public function broadcastOn(): array { return [new PrivateChannel('market.updates')]; }
    public function broadcastWith(): array { return $this->snapshot; }
}

class CandleUpdated implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public string $symbol, public string $timeframe, public array $candle) {}
    public function broadcastOn(): array { return [new PrivateChannel('market.updates')]; }
    public function broadcastWith(): array { return ['symbol' => $this->symbol, 'timeframe' => $this->timeframe, 'candle' => $this->candle]; }
}

class PairRankingUpdated implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public array $data) {}
    public function broadcastOn(): array { return [new PrivateChannel('market.analysis')]; }
    public function broadcastWith(): array { return ['at' => now()->toIso8601String(), 'count' => count($this->data['rankings'] ?? [])]; }
}

class CurrencyResearchCompleted implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public array $data) {}
    public function broadcastOn(): array { return [new PrivateChannel('currency.intelligence')]; }
    public function broadcastWith(): array
    {
        return ['currency' => $this->data['currency'] ?? null, 'outlook' => $this->data['outlook'] ?? null, 'confidence' => $this->data['confidence'] ?? null, 'at' => now()->toIso8601String()];
    }
}

class NewsArticleDiscovered implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public array $data) {}
    public function broadcastOn(): array { return [new PrivateChannel('market.analysis')]; }
    public function broadcastWith(): array { return ['count' => count($this->data['articles'] ?? []), 'at' => now()->toIso8601String()]; }
}

class AgentExecutionStarted implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\AgentTask $task) {}
    public function broadcastOn(): array { return [new PrivateChannel('agents.activity')]; }
    public function broadcastWith(): array { return ['task_id' => $this->task->id, 'agent' => $this->task->agent_name, 'symbol' => $this->task->symbol]; }
}

class AgentExecutionCompleted implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\AgentTask $task) {}
    public function broadcastOn(): array { return [new PrivateChannel('agents.activity')]; }
    public function broadcastWith(): array { return ['task_id' => $this->task->id, 'agent' => $this->task->agent_name, 'status' => 'COMPLETED']; }
}

class AgentExecutionFailed implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\AgentTask $task) {}
    public function broadcastOn(): array { return [new PrivateChannel('agents.activity')]; }
    public function broadcastWith(): array { return ['task_id' => $this->task->id, 'agent' => $this->task->agent_name, 'status' => 'FAILED', 'error' => substr((string) $this->task->error_message, 0, 200)]; }
}

class TradeProposalCreated implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\TradeProposal $proposal) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.proposals')]; }
    public function broadcastWith(): array
    {
        return ['id' => $this->proposal->id, 'symbol' => $this->proposal->symbol, 'direction' => $this->proposal->direction, 'entry' => $this->proposal->entry, 'sl' => $this->proposal->stop_loss, 'tp' => $this->proposal->take_profit, 'strategy' => $this->proposal->strategy_code];
    }
}

class TradeProposalRejected implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public string $symbol, public string $reason) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.proposals')]; }
    public function broadcastWith(): array { return ['symbol' => $this->symbol, 'reason' => substr($this->reason, 0, 300)]; }
}

class PaperOrderCreated implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\PaperOrder $order) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.orders')]; }
    public function broadcastWith(): array { return ['id' => $this->order->id, 'symbol' => $this->order->symbol, 'type' => $this->order->order_type, 'entry' => $this->order->entry_price, 'status' => $this->order->status]; }
}

class PaperOrderFilled implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\PaperOrder $order) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.orders')]; }
    public function broadcastWith(): array { return ['id' => $this->order->id, 'symbol' => $this->order->symbol, 'fill' => $this->order->fill_price, 'status' => 'FILLED']; }
}

class PaperOrderCancelled implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\PaperOrder $order) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.orders')]; }
    public function broadcastWith(): array { return ['id' => $this->order->id, 'status' => $this->order->status]; }
}

class PaperOrderExpired implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\PaperOrder $order) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.orders')]; }
    public function broadcastWith(): array { return ['id' => $this->order->id, 'status' => 'EXPIRED']; }
}

class PaperPositionOpened implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\PaperPosition $position) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.positions')]; }
    public function broadcastWith(): array { return ['id' => $this->position->id, 'symbol' => $this->position->symbol, 'direction' => $this->position->direction, 'entry' => $this->position->entry_price]; }
}

class PaperPositionUpdated implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\PaperPosition $position) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.positions')]; }
    public function broadcastWith(): array { return ['id' => $this->position->id, 'symbol' => $this->position->symbol, 'unrealized' => $this->position->unrealized_pnl, 'price' => $this->position->current_price, 'pct' => $this->position->movePct(), 'r' => $this->position->rMultiple()]; }
}

class PaperPositionClosed implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public \App\Models\PaperPosition $position) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.positions')]; }
    public function broadcastWith(): array
    {
        return ['id' => $this->position->id, 'symbol' => $this->position->symbol, 'reason' => $this->position->close_reason, 'pnl' => $this->position->realized_pnl, 'exit' => $this->position->exit_price];
    }
}

class PerformanceMetricsUpdated implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public array $metrics) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.performance')]; }
    public function broadcastWith(): array { return $this->metrics; }
}

class LearningAnalysisCompleted implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public array $data) {}
    public function broadcastOn(): array { return [new PrivateChannel('trading.performance')]; }
    public function broadcastWith(): array { return ['at' => now()->toIso8601String()]; }
}

class SystemHealthUpdated implements ShouldBroadcast
{
    use BroadcastsAdmin;
    public function __construct(public array $health) {}
    public function broadcastOn(): array { return [new PrivateChannel('system.activity')]; }
    public function broadcastWith(): array { return $this->health; }
}
