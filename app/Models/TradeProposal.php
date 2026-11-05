<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeProposal extends Model
{
    protected $fillable = ['agent_task_id', 'market_snapshot_id', 'symbol', 'direction', 'order_type', 'entry', 'stop_loss', 'take_profit', 'strategy_code', 'confidence', 'expected_holding_hours', 'rationale', 'invalidation', 'risk_reward', 'status', 'validation_result', 'model', 'expires_at'];
    protected $casts = ['expires_at' => 'datetime'];
}
