<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperPosition extends Model
{
    protected $fillable = ['trading_account_id', 'paper_order_id', 'symbol', 'direction', 'entry_price', 'volume', 'stop_loss', 'take_profit', 'current_price', 'peak_price', 'unrealized_pnl', 'realized_pnl', 'costs', 'status', 'close_reason', 'exit_price', 'opened_at', 'closed_at', 'max_hold_until', 'next_review_at', 'thesis', 'strategy_code', 'ambiguous_exit'];
    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'max_hold_until' => 'datetime', 'next_review_at' => 'datetime', 'ambiguous_exit' => 'boolean'];

    public function order()
    {
        return $this->belongsTo(PaperOrder::class, 'paper_order_id');
    }

    /** Executable mark price: what we would actually get on close. */
    public function markPrice(): float
    {
        $ref = $this->status === 'CLOSED' ? (float) $this->exit_price : (float) ($this->current_price ?: $this->entry_price);
        return $ref > 0 ? $ref : (float) $this->entry_price;
    }

    /** Signed price-move percentage vs entry (direction-aware). */
    public function movePct(): float
    {
        $entry = (float) $this->entry_price;
        if ($entry <= 0) return 0.0;
        $move = ($this->markPrice() - $entry) / $entry * 100;
        return round(($this->direction === 'SELL' ? -$move : $move), 3);
    }

    /** Profit in units of initial risk (R-multiple). */
    public function rMultiple(): ?float
    {
        $risk = (float) ($this->order?->risk_amount ?? 0);
        if ($risk <= 0) return null;
        $pnl = $this->status === 'CLOSED' ? (float) $this->realized_pnl : (float) $this->unrealized_pnl;
        return round($pnl / $risk, 2);
    }
}
