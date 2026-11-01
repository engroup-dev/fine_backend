<?php

use App\Support\CompanyClock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ACC-07: monthly depreciation. The scheduler runs on the 1st to book the
// month that just ended. Idempotent, so a missed or repeated run is harmless —
// assets already posted for the period are skipped.
Schedule::command('accounting:run-depreciation')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping();

// HR-11: a future-dated pay change becomes the employee's current terms on its
// effective date. Idempotent; rows already in sync are left alone.
Schedule::command('hr:sync-pay-terms')
    ->dailyAt('00:15')
    ->timezone(CompanyClock::FALLBACK_TIMEZONE)
    ->withoutOverlapping();

// W6-03: nightly database backup to the private backups disk, then the
// retention sweep (14 daily + 8 weekly), and a monthly restore test of the
// newest backup (docs/phase-10 §10.8: daily dumps, restore tested monthly).
Schedule::command('backup:run --scheduled')
    ->dailyAt('02:00')
    ->timezone(CompanyClock::FALLBACK_TIMEZONE)
    ->withoutOverlapping();

Schedule::command('backup:prune')
    ->dailyAt('03:00')
    ->timezone(CompanyClock::FALLBACK_TIMEZONE)
    ->withoutOverlapping();

Schedule::command('backup:verify')
    ->monthlyOn(1, '04:00')
    ->timezone(CompanyClock::FALLBACK_TIMEZONE)
    ->withoutOverlapping();
