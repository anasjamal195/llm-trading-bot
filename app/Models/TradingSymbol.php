<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingSymbol extends Model
{
    protected $fillable = ['symbol', 'base_currency', 'quote_currency', 'is_enabled', 'is_supported', 'spread_pips', 'contract_size', 'tick_size', 'tick_value', 'commission_per_lot'];
    protected $casts = ['is_enabled' => 'boolean', 'is_supported' => 'boolean'];
}
