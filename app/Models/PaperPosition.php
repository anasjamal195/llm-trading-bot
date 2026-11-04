<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperPosition extends Model
{
    protected $fillable = ['trading_account_id', 'paper_order_id', 'symbol', 'direction', 'entry_price', 'volume', 'stop_loss', 'take_profit', 'current_price', 'unrealized_pnl', 'realized_pnl', 'costs', 'status', 'close_reason', 'exit_price', 'opened_at', 'closed_at', 'max_hold_until', 'next_review_at', 'thesis', 'strategy_code', 'ambiguous_exit'];
    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'max_hold_until' => 'datetime', 'next_review_at' => 'datetime', 'ambiguous_exit' => 'boolean'];

    public function order()
    {
        return $this->belongsTo(PaperOrder::class, 'paper_order_id');
    }
}
