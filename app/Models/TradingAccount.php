<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingAccount extends Model
{
    protected $fillable = ['name', 'currency', 'initial_balance', 'balance', 'equity', 'realized_pnl', 'total_costs', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
}
