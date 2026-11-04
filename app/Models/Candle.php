<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Candle extends Model
{
    protected $table = 'trading_candles';
    protected $fillable = ['symbol', 'timeframe', 'candle_time', 'open', 'high', 'low', 'close', 'tick_volume', 'spread', 'real_volume', 'source'];
    protected $casts = ['candle_time' => 'datetime'];
}
