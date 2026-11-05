<?php

use Illuminate\Support\Facades\Schedule;

// Tick-level monitoring (~1s loop, bounded 50s pass, lease-guarded).
Schedule::job(new \App\Jobs\MonitorTicksJob)->everyMinute()->withoutOverlapping(2);

// Market data sync every minute (bounded jobs).
Schedule::job(new \App\Jobs\MarketSnapshotJob)->everyFiveMinutes()->withoutOverlapping(5);
// Candle sync per enabled symbol/timeframe is dispatched by SyncDispatcher command each minute.
Schedule::command('bot:sync-candles')->everyMinute()->withoutOverlapping(2);
Schedule::command('bot:process-orders')->everyTwoMinutes()->withoutOverlapping(3);
Schedule::command('bot:monitor-positions')->everyTwoMinutes()->withoutOverlapping(3);
Schedule::command('bot:dispatch-agents')->everyFiveMinutes()->withoutOverlapping(4);
Schedule::command('bot:expire-recover')->everyFiveMinutes()->withoutOverlapping(4);
Schedule::command('bot:health')->everyFiveMinutes()->withoutOverlapping(3);
Schedule::job(new \App\Jobs\DailyReportJob)->dailyAt('23:55');
Schedule::job(new \App\Jobs\StrategyEvaluationJob)->weeklyOn(0, '01:00');
