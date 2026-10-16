<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send FCM notifications when tasks are starting now
// This sends push notifications with Complete/Skip action buttons
Schedule::command('tasks:send-start-notifications')->everyMinute();

// Legacy reminders (disabled - using FCM instead)
// Schedule::command('app:send-task-reminders --type=reminder')->everyMinute();
