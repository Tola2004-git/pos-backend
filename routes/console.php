<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs right at the start of the new day, so the command's own "no date ->
// today" default would export the day that just began (0 orders) instead of
// the one that just ended - pass yesterday's date explicitly. Orders still
// keep that day's export current in real time as they complete
// (OrderController::store()); this scheduled run exists specifically to
// guarantee a record exists even for a day with zero completed orders.
Schedule::command('app:export-daily-receipts', [now()->subDay()->toDateString()])
    ->dailyAt('00:00')
    ->withoutOverlapping();

Schedule::command('app:backup-database')
    ->dailyAt('01:30')
    ->withoutOverlapping();
