<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ZKTeco biometric intake: map fresh device punches into daily
// student rosters every minute (raw punches are always stored first).
Schedule::command('attendance:sync-bio')->everyMinute();
