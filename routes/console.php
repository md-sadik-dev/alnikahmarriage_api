<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Automatic Daily Database Backup at 02:00 AM
Schedule::command('backup:run --type=auto')->dailyAt('02:00');

// Membership Package Expiry Push Reminders daily at 09:00 AM
Schedule::command('package:send-expiry-reminders')->dailyAt('09:00');

