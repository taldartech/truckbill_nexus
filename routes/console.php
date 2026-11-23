<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscription:dispatch-trial-reminders')->dailyAt('01:15');
Schedule::command('subscription:evaluate-lifecycle --storage')->dailyAt('01:30');
Schedule::command('subscription:apply-scheduled-changes')->dailyAt('01:45');
Schedule::command('subscription:retry-invoice-emails')->dailyAt('02:00');
// Renewal reminder windows are not product-approved — do not invent a schedule (see PO decision package).

Schedule::command('tax:reset-transporter-declarations')
    ->yearlyOn(4, 1, '00:05')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();
