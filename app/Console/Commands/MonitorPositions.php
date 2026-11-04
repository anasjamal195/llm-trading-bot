<?php

namespace App\Console\Commands;

use App\Jobs\MonitorOpenTradesJob;
use App\Models\BotSession;

use Illuminate\Console\Command;

class MonitorPositions extends Command
{
    protected $signature = 'bot:monitor-positions';
    protected $description = 'Monitor open positions (SL/TP/max-hold) — runs even when paused';

    public function handle(): int
    {
        if (BotSession::current()->status === BotSession::STOPPED) return 0;
        MonitorOpenTradesJob::dispatch()->onQueue('trade-monitoring');
        return 0;
    }
}
