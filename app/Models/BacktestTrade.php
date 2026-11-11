<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestTrade extends Model
{
    protected $fillable = ['backtest_run_id', 'direction', 'entry', 'exit', 'pnl', 'opened_at', 'closed_at', 'stop_loss', 'take_profit', 'volume', 'costs', 'close_reason', 'rationale'];
    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
}
