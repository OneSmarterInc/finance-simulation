<?php

use App\Services\Quarters\QuarterScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Walkthrough item 46: quarters close at their deadline and the next one
 * opens without anyone pressing a button. The server must run
 * `php artisan schedule:run` every minute (cron) and a queue worker.
 */
Artisan::command('flexee:quarters', function (QuarterScheduler $scheduler) {
    foreach ($scheduler->tick() as $line) {
        $this->line($line);
    }
})->purpose('Close quarters at their deadline and open the next quarter');

Schedule::command('flexee:quarters')->everyMinute()->withoutOverlapping()->onOneServer();
