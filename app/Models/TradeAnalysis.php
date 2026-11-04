<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeAnalysis extends Model
{
    protected $table = 'trade_analyses';
    protected $fillable = ['paper_position_id', 'prediction_summary', 'outcome_summary', 'entry_quality', 'exit_quality', 'lessons', 'metrics', 'model'];
    protected $casts = ['metrics' => 'array'];
}
