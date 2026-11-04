<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingStrategy extends Model
{
    protected $fillable = ['code', 'name', 'description', 'parameters', 'is_enabled', 'version'];
    protected $casts = ['parameters' => 'array', 'is_enabled' => 'boolean'];
}
