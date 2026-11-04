<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketSnapshot extends Model
{
    protected $fillable = ['symbol', 'price', 'timeframe', 'candle_time', 'atr', 'volatility_pct', 'trend', 'regime', 'indicators'];
    protected $casts = ['candle_time' => 'datetime', 'indicators' => 'array'];
}
