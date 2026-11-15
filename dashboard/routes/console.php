<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Fleet monitoring. Requires the scheduler to run: `php artisan schedule:work` (container/dev)
 * or a cron entry `* * * * * php /path/to/artisan schedule:run`.
 */
Schedule::command('sites:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping(30);

Schedule::command('sites:sync --with-system-info')
    ->dailyAt('03:30')
    ->withoutOverlapping(60);
