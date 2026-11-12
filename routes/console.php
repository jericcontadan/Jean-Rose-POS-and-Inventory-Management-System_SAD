<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily jobs (docs/04-business-rules.md). On the server, cron runs "php artisan schedule:run" every minute.
// 06:00 - work out every stock_status again (near expiry changes with the date) and fix the alerts.
Schedule::command('pos:refresh-stock-status')->dailyAt('06:00')->timezone('Asia/Manila');

// 08:00 - email the new alerts to the active owners (after the 06:00 refresh, so the list is up to date).
Schedule::command('pos:send-daily-alerts')->dailyAt('08:00')->timezone('Asia/Manila');
