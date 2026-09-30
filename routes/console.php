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
