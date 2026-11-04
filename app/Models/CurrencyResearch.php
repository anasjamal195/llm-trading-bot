<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyResearch extends Model
{
    protected $table = 'currency_research';
    protected $fillable = ['currency', 'outlook', 'confidence', 'fundamental_outlook', 'news_sentiment', 'evidence', 'expires_at', 'model'];
    protected $casts = ['expires_at' => 'datetime'];
}
