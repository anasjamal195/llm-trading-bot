<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImprovementProposal extends Model
{
    protected $fillable = ['title', 'hypothesis', 'candidate_config', 'status', 'evaluation'];
    protected $casts = ['candidate_config' => 'array', 'evaluation' => 'array'];
}
