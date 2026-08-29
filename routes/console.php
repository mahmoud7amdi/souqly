<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Three jobs, which is the whole of item 12 after the cull recorded in NOTES
| §19.2. Everything else the source system scheduled — reward-point expiry,
| payment reminders, recurring *invoices* — was dropped because the features
| behind them are not built here, and a cron for a feature that does not exist
| is not a feature, it is a nightly no-op that looks like coverage.
|
| Times are staggered and none of them sit on the hour. Three jobs firing at
| 00:00 would contend for the same connection pool, and :00 is where every other
| cron on a shared box already is.
|
| One requirement to run any of this — a single system cron entry:
|
|     * * * * * cd /path-to-souqly && php artisan schedule:run >> /dev/null 2>&1
|
| On Windows, the equivalent Task Scheduler entry running every minute. Without
| it these definitions are inert, which is worth stating plainly: nothing here
| self-starts.
|
| The scheduler resolves these against `config('app.timezone')`, not against the
| per-business timezone the `Timezone` middleware applies to HTTP requests. For a
| single-country deployment (Decision #2 — Egypt) that is the same thing; it stops
| being the same thing the day a tenant trades in another timezone, and this is
| the comment that will say so when that happens.
|
*/

// Just after midnight, so "due today" is evaluated against a date that has
// actually turned over. The generator derives the next occurrence from the last
// child that exists rather than from today, so a missed night self-heals on the
// following run instead of skipping a month.
Schedule::command('souqly:recurring-expenses')
    ->dailyAt('00:20')
    ->withoutOverlapping();

// The quietest part of the night for a shop, and late enough that the day's
// closing writes are long finished.
Schedule::command('souqly:backup')
    ->dailyAt('02:40')
    // A dump of a large database can outlast the next tick. Two mysqldumps
    // writing concurrently would double the I/O for no extra safety.
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));

// Before the shop opens, so the bell already has it when staff sign in — an
// alert that arrives mid-afternoon has missed the morning's ordering.
Schedule::command('souqly:stock-alerts')
    ->dailyAt('07:10')
    ->withoutOverlapping();
