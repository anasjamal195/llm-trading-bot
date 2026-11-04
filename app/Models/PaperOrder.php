<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperOrder extends Model
{
    protected $fillable = ['trading_account_id', 'trade_proposal_id', 'symbol', 'order_type', 'direction', 'entry_price', 'stop_loss', 'take_profit', 'volume', 'risk_amount', 'status', 'fill_price', 'filled_at', 'expires_at', 'reject_reason', 'ambiguous_fill', 'created_by'];
    protected $casts = ['filled_at' => 'datetime', 'expires_at' => 'datetime', 'ambiguous_fill' => 'boolean'];

    public function tradeProposal()
    {
        return $this->belongsTo(TradeProposal::class, 'trade_proposal_id');
    }

    public function position()
    {
        return $this->hasOne(PaperPosition::class, 'paper_order_id');
    }
}
