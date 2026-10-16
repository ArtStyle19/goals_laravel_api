<?php

namespace App\Providers;

use App\Services\FCMService;
use App\Services\TaskNotificationScheduler;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register FCM Service as singleton
        $this->app->singleton(FCMService::class, function ($app) {
            return new FCMService();
        });

        // Register Task Notification Scheduler
        $this->app->singleton(TaskNotificationScheduler::class, function ($app) {
            return new TaskNotificationScheduler(
                $app->make(FCMService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
