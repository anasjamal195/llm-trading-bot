<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestRun extends Model
{
    protected $fillable = ['strategy_code', 'symbol', 'timeframe', 'from', 'to', 'parameters', 'status', 'results'];
    protected $casts = ['from' => 'datetime', 'to' => 'datetime', 'parameters' => 'array', 'results' => 'array'];
}
