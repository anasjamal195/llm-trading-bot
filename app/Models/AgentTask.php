<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentTask extends Model
{
    protected $fillable = ['task_type', 'agent_name', 'symbol', 'currency', 'priority', 'status', 'input_context', 'output_result', 'scheduled_at', 'started_at', 'completed_at', 'next_run_at', 'retry_count', 'error_message', 'lease_owner', 'lease_expires_at', 'dedupe_key'];
    protected $casts = ['input_context' => 'array', 'output_result' => 'array', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'next_run_at' => 'datetime', 'lease_expires_at' => 'datetime'];

    public static function dispatchOnce(array $attrs): self
    {
        if (! empty($attrs['dedupe_key'])) {
            $existing = static::where('dedupe_key', $attrs['dedupe_key'])
                ->whereIn('status', ['PENDING', 'RUNNING'])->first();
            if ($existing) return $existing;
        }
        return static::create($attrs);
    }
}
