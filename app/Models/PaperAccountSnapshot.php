<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperAccountSnapshot extends Model
{
    protected $fillable = ['trading_account_id', 'balance', 'equity', 'unrealized_pnl', 'realized_pnl', 'open_positions', 'pending_orders', 'snapshot_date'];
    protected $casts = ['snapshot_date' => 'date'];
}
