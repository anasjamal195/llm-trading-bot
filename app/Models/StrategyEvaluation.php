<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StrategyEvaluation extends Model
{
    protected $fillable = ['trading_strategy_id', 'version', 'total_trades', 'win_rate', 'profit_factor', 'expectancy', 'net_profit', 'max_drawdown', 'verdict', 'details'];
    protected $casts = ['details' => 'array'];
}
