<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PaperTransaction extends Model
{
    protected $fillable = ['trading_account_id', 'type', 'amount', 'balance_after', 'reference_type', 'reference_id', 'notes', 'idempotency_key'];

    public static function record(TradingAccount $account, string $type, float $amount, $ref = null, string $notes = ''): self
    {
        return static::create([
            'trading_account_id' => $account->id,
            'type' => $type,
            'amount' => round($amount, 2),
            'balance_after' => round((float) $account->balance, 2),
            'reference_type' => $ref ? get_class($ref) : null,
            'reference_id' => $ref?->id,
            'notes' => $notes,
            'idempotency_key' => $type . '-' . ($ref ? get_class($ref) . '-' . $ref->id : 'manual') . '-' . uniqid('', true),
        ]);
    }
}
