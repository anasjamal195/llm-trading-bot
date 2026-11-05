<?php

namespace App\Services\Trading;

use App\Events\PaperOrderCreated;
use App\Events\TradeProposalCreated;
use App\Events\TradeProposalRejected;
use App\Models\AgentTask;
use App\Models\PaperOrder;
use App\Models\Setting;
use App\Models\TradeProposal;
use App\Models\TradingAccount;
use App\Services\Risk\RiskManagementService;


/**
 * Validates LLM trade decisions deterministically and creates pending limit orders.
 */
class ProposalService
{
    public function __construct(private RiskManagementService $risk, private \App\Services\System\AppLogger $log) {}

    public function handleDecision(AgentTask $task, array $data): void
    {
        if (($data['decision'] ?? 'NO_TRADE') === 'NO_TRADE') {
            TradeProposal::create([
                'agent_task_id' => $task->id,
                'symbol' => $task->symbol ?? ($data['symbol'] ?? 'UNKNOWN'),
                'direction' => 'NONE', 'order_type' => 'NONE',
                'entry' => 0, 'stop_loss' => 0, 'take_profit' => 0,
                'strategy_code' => $data['strategy'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'rationale' => $data['rationale'] ?? 'NO_TRADE',
                'status' => 'REJECTED',
                'validation_result' => 'Agent declined: NO_TRADE',
                'model' => Setting::get('openrouter.model.trade_decision', 'default'),
            ]);
            TradeProposalRejected::dispatch($task->symbol ?? '', 'Agent returned NO_TRADE');
            return;
        }

        $check = $this->risk->validateProposal($data);
        $entry = (float) $data['entry']; $sl = (float) $data['stop_loss']; $tp = (float) $data['take_profit'];
        $rr = abs($entry - $sl) > 0 ? round(abs($tp - $entry) / abs($entry - $sl), 3) : 0;

        $proposal = TradeProposal::create([
            'agent_task_id' => $task->id,
            'market_snapshot_id' => $task->input_context['market_snapshot_id'] ?? null,
            'symbol' => $data['symbol'],
            'direction' => strtoupper($data['direction']),
            'order_type' => $data['order_type'],
            'entry' => $entry, 'stop_loss' => $sl, 'take_profit' => $tp,
            'strategy_code' => $data['strategy'] ?? null,
            'confidence' => $data['confidence'] ?? null,
            'probability' => isset($data['probability']) ? max(0, min(1, (float) $data['probability'])) : null,
            'expected_holding_hours' => isset($data['expected_holding_hours']) ? max(1, (int) $data['expected_holding_hours']) : null,
            'rationale' => $data['rationale'] ?? null,
            'invalidation' => $data['invalidation'] ?? null,
            'risk_reward' => $rr,
            'status' => $check['approved'] ? 'APPROVED' : 'REJECTED',
            'validation_result' => $check['approved'] ? 'OK' : implode('; ', $check['reasons']),
            'model' => Setting::get('openrouter.model.trade_decision', 'default'),
            'expires_at' => now()->addMinutes((int) ($data['expiry_minutes'] ?? 120)),
        ]);

        if (! $check['approved']) {
            TradeProposalRejected::dispatch($proposal->symbol, implode('; ', $check['reasons']));
            $this->log->warning('trading', "Proposal #{$proposal->id} REJECTED {$proposal->symbol}", ['reasons' => $check['reasons']]);
            return;
        }

        $account = TradingAccount::where('is_active', true)->first();
        $maxLifetime = (int) Setting::get('risk.max_pending_order_hours', 24);
        $expiry = now()->addMinutes(min((int) ($data['expiry_minutes'] ?? 120), $maxLifetime * 60));

        $order = PaperOrder::create([
            'trading_account_id' => $account->id,
            'trade_proposal_id' => $proposal->id,
            'symbol' => $proposal->symbol,
            'order_type' => $proposal->order_type,
            'direction' => $proposal->direction,
            'entry_price' => $entry, 'stop_loss' => $sl, 'take_profit' => $tp,
            'volume' => $check['volume'],
            'risk_amount' => $check['risk_amount'],
            'status' => 'PENDING',
            'expires_at' => $expiry,
            'created_by' => 'trade_decision',
        ]);
        $proposal->update(['status' => 'ORDERED']);
        $this->log->info('trading', "Proposal #{$proposal->id} APPROVED → order #{$order->id} {$proposal->symbol} {$proposal->direction} vol {$check['volume']}", ['risk' => $check['risk_amount']]);
        \App\Events\PaperOrderCreated::dispatch($order);
        TradeProposalCreated::dispatch($proposal);
    }
}
