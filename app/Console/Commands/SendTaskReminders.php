<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\FCMService;
use App\Services\TaskNotificationScheduler;
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
    protected $signature = 'app:send-task-reminders {--type=reminder : Type of notification (reminder|start|overdue)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send FCM notifications for tasks with action buttons (Complete, Skip)';

    private FCMService $fcmService;
    private TaskNotificationScheduler $scheduler;

    public function __construct(FCMService $fcmService, TaskNotificationScheduler $scheduler)
    {
        parent::__construct();
        $this->fcmService = $fcmService;
        $this->scheduler = $scheduler;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $this->info("Checking for tasks to send {$type} notifications...");

        if (!$this->fcmService->isInitialized()) {
            $this->error('FCM service not initialized. Check credentials.');
            Log::error('SendTaskReminders: FCM service not initialized');
            return 1;
        }

        // Determine time window based on notification type
        $now = Carbon::now();

        switch ($type) {
            case 'start':
                // Tasks starting now (within 1 minute window)
                $windowStart = $now->copy()->subMinute();
                $windowEnd = $now->copy()->addMinute();
                $notificationType = 'task_start';
                break;
            case 'overdue':
                // Tasks that should have started but haven't
                $windowStart = $now->copy()->subHours(1);
                $windowEnd = $now->copy()->subMinute();
                $notificationType = 'task_overdue';
                break;
            case 'reminder':
            default:
                // Tasks starting in 15 minutes
                $targetTime = $now->copy()->addMinutes(15);
                $windowStart = $targetTime->copy()->subMinute();
                $windowEnd = $targetTime->copy()->addMinute();
                $notificationType = 'task_reminder';
                break;
        }

        $tasks = Task::with('goal.user')
            ->where('scheduled_at', '>=', $windowStart)
            ->where('scheduled_at', '<=', $windowEnd)
            ->where('status', 'scheduled')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No tasks found for notification.');
            return 0;
        }

        $this->info("Found {$tasks->count()} task(s) for {$type} notification.");

        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($tasks as $task) {
            $user = $task->goal->user ?? null;

            if (!$user || !$user->fcm_token) {
                $this->warn("Skipping task {$task->id}: No FCM token for user.");
                continue;
            }

            // Check if already sent (prevent duplicates)
            $cacheKey = "task_notification_sent:{$task->id}:{$type}";
            if (Cache::has($cacheKey)) {
                $this->warn("Skipping task {$task->id}: Notification already sent.");
                $skippedCount++;
                continue;
            }

            // Send notification with action buttons using the scheduler
            $result = $this->scheduler->sendImmediateNotification($task, $notificationType);

            if ($result['success']) {
                // Mark as sent (expire after 2 hours to prevent memory bloat)
                Cache::put($cacheKey, true, now()->addHours(2));
                $sentCount++;
                $this->info("✓ {$type} notification sent for task {$task->id} to user {$user->id}");
            } elseif ($result['message'] === 'Notification already sent recently') {
                $skippedCount++;
                $this->warn("Skipping task {$task->id}: {$result['message']}");
            } else {
                $failedCount++;
                $this->error("✗ Failed to send {$type} notification for task {$task->id}: {$result['message']}");

                // If token is invalid, clear it
                if (isset($result['invalid_token']) && $result['invalid_token']) {
                    $user->fcm_token = null;
                    $user->save();
                    $this->warn("  Cleared invalid FCM token for user {$user->id}");
                }
            }
        }

        $this->info("Completed: {$sentCount} sent, {$skippedCount} skipped, {$failedCount} failed.");
        Log::info('SendTaskReminders completed', [
            'type' => $type,
            'total_tasks' => $tasks->count(),
            'sent' => $sentCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
        ]);

        return 0;
    }
}
