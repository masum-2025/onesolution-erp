<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Confirmed module data purges run once their waiting period has passed.
Schedule::command('modules:purge-due')->dailyAt('02:00')->withoutOverlapping()->onOneServer();

// Support access that ran out is closed and shown in the client's audit log.
Schedule::command('support:expire')->everyMinute()->withoutOverlapping()->onOneServer();

// Data export files are deleted after their retention period.
Schedule::command('exports:prune')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
