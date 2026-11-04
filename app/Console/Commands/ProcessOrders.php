<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPendingOrdersJob;
use App\Models\BotSession;

use Illuminate\Console\Command;

class ProcessOrders extends Command
{
    protected $signature = 'bot:process-orders';
    protected $description = 'Evaluate pending orders against latest candles';

    public function handle(): int
    {
        if (BotSession::current()->status === BotSession::STOPPED) return 0;
        ProcessPendingOrdersJob::dispatch()->onQueue('trading');
        return 0;
    }
}
