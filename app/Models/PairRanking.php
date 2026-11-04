<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PairRanking extends Model
{
    protected $fillable = ['symbol', 'score', 'suitable', 'regime', 'reasons'];
    protected $casts = ['suitable' => 'boolean'];
}
