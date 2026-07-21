<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send appointment reminders (E8-2). Runs often; each booking reminded once.
Schedule::command('appointments:send-reminders')->everyFifteenMinutes()->withoutOverlapping();
