<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('hbx:availability:cleanup')
    ->daily()
    ->withoutOverlapping()
    ->description('Delete unbooked availability snapshots and old availability logs past the debug retention window');

$differentialSchedule = config('hbx.content.differential_schedule');

if (is_string($differentialSchedule) && preg_match('/^[\d*\/,\-\s]+$/', trim($differentialSchedule)) === 1 && substr_count(trim($differentialSchedule), ' ') >= 4) {
    Schedule::command('hbx:content:sync-hotels --language=ENG --last-update='.now()->subDay()->toDateString())
        ->cron(trim($differentialSchedule))
        ->withoutOverlapping()
        ->description('Optional differential content sync. Disabled unless HBX_CONTENT_DIFFERENTIAL_SCHEDULE is set.');
}
