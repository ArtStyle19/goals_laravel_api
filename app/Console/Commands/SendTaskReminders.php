<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\FCMService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendTaskReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-task-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send FCM notifications for tasks starting in 15 minutes';

    private FCMService $fcmService;

    public function __construct(FCMService $fcmService)
    {
        parent::__construct();
        $this->fcmService = $fcmService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for upcoming tasks...');

        if (!$this->fcmService->isInitialized()) {
            $this->error('FCM service not initialized. Check credentials.');
            Log::error('SendTaskReminders: FCM service not initialized');
            return 1;
        }

        // Find tasks starting in exactly 15 minutes (with 1-minute window)
        $now = Carbon::now();
        $targetTime = $now->copy()->addMinutes(15);
        $windowStart = $targetTime->copy()->subMinute();
        $windowEnd = $targetTime->copy()->addMinute();

        $tasks = Task::with('goal.user')
            ->where('scheduled_at', '>=', $windowStart)
            ->where('scheduled_at', '<=', $windowEnd)
            ->where('status', 'scheduled')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No tasks found for reminder.');
            return 0;
        }

        $this->info("Found {$tasks->count()} task(s) for reminder.");

        $sentCount = 0;
        $failedCount = 0;

        foreach ($tasks as $task) {
            $user = $task->goal->user ?? null;

            if (!$user || !$user->fcm_token) {
                $this->warn("Skipping task {$task->id}: No FCM token for user.");
                continue;
            }

            // Check if already sent (prevent duplicates)
            $cacheKey = "task_reminder_sent:{$task->id}";
            if (Cache::has($cacheKey)) {
                $this->warn("Skipping task {$task->id}: Reminder already sent.");
                continue;
            }

            // Send notification
            $result = $this->fcmService->sendTaskReminder($user, $task);

            if ($result['success']) {
                // Mark as sent (expire after 2 hours to prevent memory bloat)
                Cache::put($cacheKey, true, now()->addHours(2));
                $sentCount++;
                $this->info("✓ Reminder sent for task {$task->id} to user {$user->id}");
            } else {
                $failedCount++;
                $this->error("✗ Failed to send reminder for task {$task->id}: {$result['message']}");

                // If token is invalid, clear it
                if (isset($result['invalid_token']) && $result['invalid_token']) {
                    $user->fcm_token = null;
                    $user->save();
                    $this->warn("  Cleared invalid FCM token for user {$user->id}");
                }
            }
        }

        $this->info("Completed: {$sentCount} sent, {$failedCount} failed.");
        Log::info('SendTaskReminders completed', [
            'total_tasks' => $tasks->count(),
            'sent' => $sentCount,
            'failed' => $failedCount,
        ]);

        return 0;
    }
}
