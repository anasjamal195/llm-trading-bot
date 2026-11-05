<?php

namespace App\Services\PaperBroker;

use App\Events\PaperOrderCancelled;
use App\Events\PaperOrderExpired;
use App\Events\PaperOrderFilled;
use App\Events\PaperPositionClosed;
use App\Events\PaperPositionOpened;
use App\Events\PaperPositionUpdated;
use App\Models\Candle;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\PaperTransaction;
use App\Models\Setting;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;

use Illuminate\Support\Facades\DB;

/**
 * Internal paper-trading broker. The ONLY execution environment.
 * Works exclusively from completed candle OHLC + spread model.
 * Conservative ambiguity: when SL and TP are both touched in one candle,
 * the less favorable outcome (SL) is assumed.
 */
class PaperBrokerService
{
    public function __construct(private \App\Services\System\AppLogger $log) {}
    public function spreadPrice(TradingSymbol $ts, float $mid, string $side): float
    {
        // Spread stored in pips; price offset = spread_pips * pipSize / 2 per side.
        // pip_size is per-symbol (forex 0.0001, JPY 0.01, metals 0.1).
        $pip = (float) ($ts->pip_size ?? 0);
        if ($pip <= 0) $pip = str_ends_with($ts->symbol, 'JPY') ? 0.01 : 0.0001;
        $half = ((float) $ts->spread_pips * $pip) / 2;
        return $side === 'bid' ? $mid - $half : $mid + $half;
    }

    /** Evaluate all pending orders against the latest completed candle. */
    public function processPendingOrders(string $symbol, Candle $candle): array
    {
        $results = [];
        $orders = PaperOrder::where('symbol', $symbol)->where('status', 'PENDING')
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($orders as $order) {
            $results[] = $this->evaluateOrder($order, $candle);
        }
        return $results;
    }

    private function evaluateOrder(PaperOrder $order, Candle $candle): array
    {
        return DB::transaction(function () use ($order, $candle) {
            $order = PaperOrder::where('id', $order->id)->lockForUpdate()->first();
            if (! $order || $order->status !== 'PENDING') {
                return ['order_id' => $order?->id, 'action' => 'SKIPPED'];
            }
            if (now()->greaterThan($order->expires_at)) {
                $order->update(['status' => 'EXPIRED']);
                $this->log->info('broker', "Order #{$order->id} EXPIRED {$order->symbol}");
                PaperOrderExpired::dispatch($order);
                return ['order_id' => $order->id, 'action' => 'EXPIRED'];
            }

            $ts = TradingSymbol::where('symbol', $order->symbol)->first();
            $high = (float) $candle->high; $low = (float) $candle->low;
            $entry = (float) $order->entry_price;
            $touched = $low <= $entry && $entry <= $high;

            // BUY LIMIT fills when price reaches down to entry (low <= entry).
            // SELL LIMIT fills when price reaches up to entry (high >= entry).
            // Both reduce to: entry within [low, high]. Conservative fill at entry.
            if (! $touched) {
                return ['order_id' => $order->id, 'action' => 'NO_FILL'];
            }

            // Immediate SL/TP ambiguity on the fill candle: if fill candle also
            // touches SL, assume SL hit (conservative). Record flag.
            $sl = (float) $order->stop_loss; $tp = (float) $order->take_profit;
            $slTouched = $low <= $sl && $sl <= $high;
            $tpTouched = $low <= $tp && $tp <= $high;
            $ambiguous = $slTouched || $tpTouched;

            $account = TradingAccount::where('id', $order->trading_account_id)->lockForUpdate()->first();
            $costs = $this->executionCosts($ts, (float) $order->volume);

            $order->update([
                'status' => 'FILLED',
                'fill_price' => $entry,
                'filled_at' => now(),
                'ambiguous_fill' => $ambiguous,
            ]);

            $maxHoldHours = (int) Setting::get('risk.max_holding_hours', 48);
            // Scalp enforcement: the position cannot outlive the proposal's
            // expected holding time (capped additionally by the risk setting).
            $expected = (int) ($order->tradeProposal?->expected_holding_hours ?? 0);
            if ($expected > 0) $maxHoldHours = min($maxHoldHours, $expected);
            $maxHoldHours = max(1, $maxHoldHours);
            $position = PaperPosition::create([
                'trading_account_id' => $order->trading_account_id,
                'paper_order_id' => $order->id,
                'symbol' => $order->symbol,
                'direction' => $order->direction,
                'entry_price' => $entry,
                'volume' => $order->volume,
                'stop_loss' => $sl,
                'take_profit' => $tp,
                'current_price' => $entry,
                'costs' => $costs,
                'status' => 'OPEN',
                'opened_at' => now(),
                'max_hold_until' => now()->addHours($maxHoldHours),
                'next_review_at' => now()->addMinutes(15),
                'thesis' => $order->tradeProposal?->rationale,
                'strategy_code' => $order->tradeProposal?->strategy_code,
                'ambiguous_exit' => false,
            ]);

            // Record costs immediately.
            if ($costs > 0) {
                $account->balance -= $costs;
                $account->total_costs += $costs;
                $account->save();
                PaperTransaction::record($account, 'COMMISSION', -$costs, $position, "Fill costs order #{$order->id}");
            }
            $this->refreshEquity($account);

            $this->log->info('broker', "Order #{$order->id} FILLED {$order->symbol} {$order->direction} @ {$entry}", ['position_id' => $position->id ?? null, 'ambiguous' => $ambiguous]);
            PaperOrderFilled::dispatch($order);
            PaperPositionOpened::dispatch($position);
            return ['order_id' => $order->id, 'action' => 'FILLED', 'position_id' => $position->id, 'ambiguous' => $ambiguous];
        });
    }

    /** Monitor open positions against latest candle: SL/TP/max-hold. Idempotent. */
    public function monitorPositions(string $symbol, Candle $candle): array
    {
        $out = [];
        $positions = PaperPosition::where('symbol', $symbol)->where('status', 'OPEN')->orderBy('id')->get();
        foreach ($positions as $position) {
            $out[] = $this->evaluatePosition($position, $candle, 'SYSTEM');
        }
        return $out;
    }

    public function evaluatePosition(PaperPosition $position, Candle $candle, string $reason = 'SYSTEM'): array
    {
        return DB::transaction(function () use ($position, $candle, $reason) {
            $p = PaperPosition::where('id', $position->id)->lockForUpdate()->first();
            if (! $p || $p->status !== 'OPEN') return ['position_id' => $position->id, 'action' => 'SKIPPED'];

            $high = (float) $candle->high; $low = (float) $candle->low;
            $close = (float) $candle->close;
            $sl = (float) $p->stop_loss; $tp = (float) $p->take_profit;
            $isBuy = $p->direction === 'BUY';

            $slHit = $isBuy ? ($low <= $sl) : ($high >= $sl);
            $tpHit = $isBuy ? ($high >= $tp) : ($low <= $tp);

            $exit = null; $closeReason = null; $ambiguous = false;
            if ($slHit && $tpHit) {
                // Conservative: SL first.
                $exit = $sl; $closeReason = 'STOP_LOSS'; $ambiguous = true;
            } elseif ($slHit) {
                $exit = $sl; $closeReason = 'STOP_LOSS';
            } elseif ($tpHit) {
                $exit = $tp; $closeReason = 'TAKE_PROFIT';
            } elseif (now()->greaterThan($p->max_hold_until)) {
                $exit = $close; $closeReason = 'MAX_HOLD';
            }

            // Mark-to-market.
            $p->current_price = $close;
            $p->unrealized_pnl = $this->pnl($p->symbol, $p->direction, (float) $p->entry_price, $close, (float) $p->volume);
            $p->next_review_at = now()->addMinutes(15);
            $p->save();

            if ($exit === null) {
                $this->refreshEquity($p->trading_account_id);
                PaperPositionUpdated::dispatch($p);
                return ['position_id' => $p->id, 'action' => 'HOLD', 'unrealized' => $p->unrealized_pnl];
            }
            return $this->closePosition($p, $exit, $closeReason, $ambiguous);
        });
    }

    /** Manual/admin or agent-requested early close. Idempotent. */
    public function closeEarly(PaperPosition $position, string $reason = 'MANUAL'): array
    {
        $candle = Candle::where('symbol', $position->symbol)
            ->orderBy('candle_time', 'desc')->first();
        $price = $candle ? (float) $candle->close : (((float) $position->current_price) ?: (float) $position->entry_price);
        return DB::transaction(function () use ($position, $price, $reason) {
            $p = PaperPosition::where('id', $position->id)->lockForUpdate()->first();
            if (! $p || $p->status !== 'OPEN') return ['position_id' => $position->id, 'action' => 'SKIPPED'];
            return $this->closePosition($p, $price, $reason, false);
        });
    }

    public function cancelOrder(PaperOrder $order): bool
    {
        return DB::transaction(function () use ($order) {
            $o = PaperOrder::where('id', $order->id)->lockForUpdate()->first();
            if (! $o || $o->status !== 'PENDING') return false;
            $o->update(['status' => 'CANCELLED']);
            PaperOrderCancelled::dispatch($o);
            return true;
        });
    }

    public function expireDueOrders(): int
    {
        $n = 0;
        PaperOrder::where('status', 'PENDING')->where('expires_at', '<=', now())
            ->orderBy('id')->chunkById(100, function ($orders) use (&$n) {
                foreach ($orders as $o) {
                    if ($this->cancelOrExpire($o)) $n++;
                }
            });
        return $n;
    }

    private function cancelOrExpire(PaperOrder $order): bool
    {
        return DB::transaction(function () use ($order) {
            $o = PaperOrder::where('id', $order->id)->lockForUpdate()->first();
            if (! $o || $o->status !== 'PENDING') return false;
            $o->update(['status' => 'EXPIRED']);
            PaperOrderExpired::dispatch($o);
            return true;
        });
    }

    private function closePosition(PaperPosition $p, float $exit, string $reason, bool $ambiguous): array
    {
        $account = TradingAccount::where('id', $p->trading_account_id)->lockForUpdate()->first();
        $gross = $this->pnl($p->symbol, $p->direction, (float) $p->entry_price, $exit, (float) $p->volume);
        $net = round($gross - (float) $p->costs, 2);

        $p->update([
            'status' => 'CLOSED', 'exit_price' => $exit, 'close_reason' => $reason,
            'realized_pnl' => $net, 'unrealized_pnl' => 0, 'closed_at' => now(),
            'ambiguous_exit' => $ambiguous,
        ]);

        $account->balance = round((float) $account->balance + $net, 2);
        $account->realized_pnl = round((float) $account->realized_pnl + $net, 2);
        $account->save();
        PaperTransaction::record($account, $net >= 0 ? 'TRADE_PROFIT' : 'TRADE_LOSS', $net, $p, "Close #{$p->id} {$reason} @ {$exit}");
        $this->refreshEquity($account);

        $this->log->info('broker', "Position #{$p->id} CLOSED {$p->symbol} {$reason} @ {$exit} P&L {$net}", ['ambiguous' => $ambiguous]);
        PaperPositionClosed::dispatch($p);
        return ['position_id' => $p->id, 'action' => 'CLOSED', 'reason' => $reason, 'exit' => $exit, 'pnl' => $net, 'ambiguous' => $ambiguous];
    }

    /** Signed P&L in account currency for a price move. */
    public function pnl(string $symbol, string $direction, float $entry, float $exit, float $volume): float
    {
        $ts = TradingSymbol::where('symbol', $symbol)->first();
        $tickSize = $ts ? (float) $ts->tick_size : 0.00001;
        $tickValue = $ts ? (float) $ts->tick_value : 1.0;
        // JPY-quoted pairs: tick_value is per-point in quote; simplified 1:1 USD conversion.
        if ($tickSize <= 0) return 0;
        $ticks = ($exit - $entry) / $tickSize;
        if ($direction === 'SELL') $ticks = -$ticks;
        return round($ticks * $tickValue * $volume, 2);
    }

    private function executionCosts(?TradingSymbol $ts, float $volume): float
    {
        if (! $ts) return 0;
        $commission = (float) $ts->commission_per_lot * $volume * 2;
        return round($commission, 2);
    }

    private function refreshEquity(TradingAccount|int $account): void
    {
        $account = $account instanceof TradingAccount ? $account : TradingAccount::find($account);
        if (! $account) return;
        $unrealized = (float) PaperPosition::where('trading_account_id', $account->id)
            ->where('status', 'OPEN')->sum('unrealized_pnl');
        $account->equity = round((float) $account->balance + $unrealized, 2);
        $account->save();
    }
}
