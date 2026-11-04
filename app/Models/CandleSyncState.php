<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandleSyncState extends Model
{
    protected $fillable = ['symbol', 'timeframe', 'last_candle_time', 'status', 'last_error', 'last_synced_at'];
    protected $casts = ['last_candle_time' => 'datetime', 'last_synced_at' => 'datetime'];
}
