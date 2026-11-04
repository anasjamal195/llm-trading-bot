<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptVersion extends Model
{
    protected $fillable = ['agent_name', 'version', 'system_prompt', 'user_template', 'is_active'];

    public static function activeFor(string $agent): ?self
    {
        return static::where('agent_name', $agent)->where('is_active', true)->orderBy('version', 'desc')->first();
    }
}
