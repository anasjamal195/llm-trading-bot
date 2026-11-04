<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSession extends Model
{
    public const STOPPED = 'STOPPED';
    public const INITIALIZING = 'INITIALIZING';
    public const RUNNING = 'RUNNING';
    public const PAUSED = 'PAUSED';
    public const DEGRADED = 'DEGRADED';
    public const ERROR = 'ERROR';
    public const STOPPING = 'STOPPING';

    protected $fillable = ['status', 'lock_owner', 'lock_expires_at', 'state_snapshot', 'started_at', 'stopped_at', 'status_message'];
    protected $casts = ['state_snapshot' => 'array', 'lock_expires_at' => 'datetime', 'started_at' => 'datetime', 'stopped_at' => 'datetime'];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['status' => self::STOPPED]);
    }
}
