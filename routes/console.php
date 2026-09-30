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

// The monthly billing run: invoices to wholesale partners and to clients (safe to repeat).
Schedule::command('billing:run')->monthlyOn(1, '01:00')->withoutOverlapping()->onOneServer();

// Self-serve accounts: trial reminders and ends, renewal invoices, overdue reminders
// and read-only (Phase 5C-2). Hourly so a trial ends close to its hour; safe to repeat.
Schedule::command('billing:self-serve')->hourlyAt(15)->withoutOverlapping()->onOneServer();

// Online payments whose gateway notice is late or lost are checked; abandoned ones expire.
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// A client's payment gateway account change takes effect once its single-approver wait is over (Phase 6).
Schedule::command('payments:apply-merchant-changes')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// Accounts whose "delete my account" grace period ended are erased (Phase 5C-3).
Schedule::command('privacy:erase-due')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// Held offline changes nobody decided on in time are discarded (Phase 7).
Schedule::command('offline:discard-expired')->dailyAt('03:45')->withoutOverlapping()->onOneServer();

// Data export files are deleted after their retention period.
Schedule::command('exports:prune')->dailyAt('03:00')->withoutOverlapping()->onOneServer();

// Encrypted backup set every night, old sets removed (Phase 8-2).
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping()->onOneServer();

// The restore drill: the newest set restored into the staging database and checked.
Schedule::command('backup:restore-drill')->monthlyOn(2, '04:00')->withoutOverlapping()->onOneServer();
