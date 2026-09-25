<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Confirmed module data purges run once their waiting period has passed.
Schedule::command('modules:purge-due')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
