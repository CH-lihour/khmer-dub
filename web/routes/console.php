<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete videos, results and worker job folders older than 7 days, every day at 00:00 UTC.
// The scheduler service in compose.yaml runs `php artisan schedule:work`.
Schedule::command('dub:cleanup')->daily()->withoutOverlapping();
