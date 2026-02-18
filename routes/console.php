<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule task reminders every minute
Schedule::command('app:send-test-notification guillermito@gmail.com')->everyMinute();
// Schedule::command('app:send-task-reminders')->everyMinute();
