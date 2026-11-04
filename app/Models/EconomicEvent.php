<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EconomicEvent extends Model
{
    protected $fillable = ['title', 'currency', 'impact', 'event_time', 'forecast', 'previous', 'actual'];
    protected $casts = ['event_time' => 'datetime'];
}
