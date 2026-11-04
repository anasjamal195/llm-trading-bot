<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelUsageLog extends Model
{
    protected $fillable = ['agent_task_id', 'agent_name', 'model', 'provider', 'input_tokens', 'output_tokens', 'latency_ms', 'estimated_cost', 'status', 'error'];
}
