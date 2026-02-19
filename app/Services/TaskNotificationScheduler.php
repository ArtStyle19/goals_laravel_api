<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Task Notification Scheduler Service
 *
 * Handles scheduling and sending of FCM notifications for tasks.
 * Uses Laravel's cache system to prevent duplicate notifications.
 *
 * Architecture:
 * - Single Responsibility: Only handles task notifications
 * - Dependency Injection: FCMService injected via constructor
 * - Cache-based deduplication: Prevents duplicate notifications
 */
class TaskNotificationScheduler
{
    private FCMService $fcmService;

    /** Cache TTL for sent notifications (5 minutes to prevent duplicates) */
    private const NOTIFICATION_SENT_TTL = 300;

    /** Time window in minutes to check for tasks starting */
    private const START_WINDOW_MINUTES = 1;

    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    /**
     * Process and send notifications for tasks starting now.
     *
     * This is the main entry point called by the scheduled command.
     * It finds tasks scheduled to start in the current minute window
     * and sends FCM notifications with action buttons.
     *
     * @param bool $dryRun If true, only simulates without sending
     * @return array Summary of processed notifications
     */
    public function processTaskStartNotifications(bool $dryRun = false): array
    {
        $now = Carbon::now();
        $windowStart = $now->copy()->subMinutes(self::START_WINDOW_MINUTES);
        $windowEnd = $now->copy()->addMinutes(self::START_WINDOW_MINUTES);

        // Find tasks starting in the window that are still scheduled
        $tasks = Task::with(['goal.user'])
            ->where('scheduled_at', '>=', $windowStart)
            ->where('scheduled_at', '<=', $windowEnd)
            ->where('status', Task::STATUS_SCHEDULED)
            ->get();

        $result = [
            'tasks_found' => $tasks->count(),
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'no_token' => 0,
            'errors' => [],
            'dry_run_details' => [],
        ];

        foreach ($tasks as $task) {
            $notificationResult = $this->sendTaskStartNotification($task, $dryRun);

            if ($dryRun && $notificationResult['would_send']) {
                $result['dry_run_details'][] = [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'user_id' => $task->goal->user_id ?? null,
                ];
            }

            switch ($notificationResult['status']) {
                case 'sent':
                    $result['sent']++;
                    break;
                case 'skipped':
                    $result['skipped']++;
                    break;
                case 'no_token':
                    $result['no_token']++;
                    break;
                case 'failed':
                    $result['failed']++;
                    $result['errors'][] = [
                        'task_id' => $task->id,
                        'message' => $notificationResult['message'],
                    ];
                    break;
            }
        }

        return $result;
    }

    /**
     * Send notification for a task that is starting now.
     *
     * Includes action buttons for Complete and Skip in the notification.
     * Uses cache to prevent sending duplicate notifications within 5 minutes.
     *
     * @param Task $task
     * @param bool $dryRun If true, simulates without sending
     * @return array Result with status: sent|skipped|no_token|failed
     */
    public function sendTaskStartNotification(Task $task, bool $dryRun = false): array
    {
        $user = $task->goal->user ?? null;

        // Validate user has FCM token
        if (!$user || !$user->fcm_token) {
            return [
                'status' => 'no_token',
                'message' => 'User has no FCM token',
                'would_send' => false,
            ];
        }

        // Check if already sent recently (prevent duplicates)
        $cacheKey = $this->getNotificationSentCacheKey($task->id, 'task_start');
        if (Cache::has($cacheKey)) {
            return [
                'status' => 'skipped',
                'message' => 'Notification already sent recently',
                'would_send' => false,
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'sent',
                'message' => 'Would send notification (dry run)',
                'would_send' => true,
            ];
        }

        // Send notification with action buttons via FCM
        $result = $this->fcmService->sendTaskNotificationWithActions(
            $user,
            $task,
            'task_start'
        );

        if ($result['success']) {
            // Mark as sent to prevent duplicates
            Cache::put($cacheKey, true, self::NOTIFICATION_SENT_TTL);

            Log::info('Task start notification sent', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'scheduled_at' => $task->scheduled_at,
            ]);

            return [
                'status' => 'sent',
                'message' => 'Notification sent successfully',
                'would_send' => false,
            ];
        }

        Log::warning('Failed to send task start notification', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'error' => $result['message'],
        ]);

        return [
            'status' => 'failed',
            'message' => $result['message'],
            'would_send' => false,
        ];
    }

    /**
     * Send immediate notification for a task (legacy compatibility).
     *
     * This method is used by SendTaskReminders command for backward compatibility.
     * It sends a notification immediately with the specified type.
     *
     * @param Task $task
     * @param string $notificationType Type: 'task_start', 'task_reminder', 'task_overdue'
     * @return array Result with success status
     */
    public function sendImmediateNotification(Task $task, string $notificationType = 'task_start'): array
    {
        $user = $task->goal->user ?? null;

        if (!$user || !$user->fcm_token) {
            return [
                'success' => false,
                'message' => 'User has no FCM token',
            ];
        }

        // Check if already sent (prevent duplicates within 5 minutes)
        $sentCacheKey = "task_notification_sent:{$task->id}:{$notificationType}";
        if (Cache::has($sentCacheKey)) {
            return [
                'success' => false,
                'message' => 'Notification already sent recently',
            ];
        }

        // Send notification with actions
        $result = $this->fcmService->sendTaskNotificationWithActions($user, $task, $notificationType);

        if ($result['success']) {
            // Mark as sent for 5 minutes to prevent duplicates
            Cache::put($sentCacheKey, true, 300);

            Log::info('Task notification sent immediately', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'type' => $notificationType,
            ]);
        }

        return $result;
    }

    /**
     * Cancel pending notifications for a task.
     *
     * Should be called when a task is completed, skipped, or deleted.
     * Clears the cache to allow future notifications if task is rescheduled.
     *
     * @param int $taskId
     * @return bool
     */
    public function cancelTaskNotifications(int $taskId): bool
    {
        $cacheKey = $this->getNotificationSentCacheKey($taskId, 'task_start');
        Cache::forget($cacheKey);

        // Also clear legacy cache keys
        Cache::forget("task_notification_sent:{$taskId}:task_start");
        Cache::forget("task_notification_sent:{$taskId}:task_reminder");
        Cache::forget("task_notification_sent:{$taskId}:task_overdue");

        Log::info('Task notifications cancelled', [
            'task_id' => $taskId,
        ]);

        return true;
    }

    /**
     * Re-schedule notifications for all upcoming tasks of a user.
     *
     * Useful when FCM token is updated or user reinstalls the app.
     * Clears sent cache so notifications can be sent again.
     *
     * @param User $user
     * @return array
     */
    public function rescheduleUserNotifications(User $user): array
    {
        if (!$user->fcm_token) {
            return [
                'success' => false,
                'message' => 'User has no FCM token',
            ];
        }

        // Get upcoming scheduled tasks
        $tasks = Task::whereHas('goal', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->where('scheduled_at', '>', Carbon::now())
            ->where('status', Task::STATUS_SCHEDULED)
            ->get();

        $cleared = 0;

        foreach ($tasks as $task) {
            // Clear cache to allow re-notification
            $this->cancelTaskNotifications($task->id);
            $cleared++;
        }

        Log::info('Rescheduled notifications for user', [
            'user_id' => $user->id,
            'cleared_count' => $cleared,
        ]);

        return [
            'success' => true,
            'cleared_count' => $cleared,
        ];
    }

    /**
     * Get cache key for sent notification tracking.
     *
     * @param int $taskId
     * @param string $type
     * @return string
     */
    private function getNotificationSentCacheKey(int $taskId, string $type): string
    {
        return "task_notification_sent:{$taskId}:{$type}";
    }
}
