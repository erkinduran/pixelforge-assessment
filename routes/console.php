<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The fallback for lost webhooks and lost jobs. The 10 minutes bound the overlap lock, so a run
// that crashed cannot block the next ones for the default 24 hours.
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping(10);
