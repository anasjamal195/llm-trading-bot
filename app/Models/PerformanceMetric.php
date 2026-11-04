<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceMetric extends Model
{
    protected $fillable = ['period_date', 'scope', 'scope_key', 'total_trades', 'wins', 'losses', 'win_rate', 'gross_profit', 'gross_loss', 'net_profit', 'profit_factor', 'expectancy', 'max_drawdown'];
    protected $casts = ['period_date' => 'date'];
}
