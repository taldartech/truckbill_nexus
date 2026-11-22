<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscription:dispatch-trial-reminders')->dailyAt('01:15');
Schedule::command('subscription:evaluate-lifecycle --storage')->dailyAt('01:30');
