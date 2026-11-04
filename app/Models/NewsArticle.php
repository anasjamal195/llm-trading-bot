<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsArticle extends Model
{
    protected $fillable = ['headline', 'source', 'url', 'published_at', 'retrieved_at', 'currency', 'event_category', 'impact', 'sentiment', 'confidence', 'expires_at', 'summary', 'hash'];
    protected $casts = ['published_at' => 'datetime', 'retrieved_at' => 'datetime', 'expires_at' => 'datetime'];
}
