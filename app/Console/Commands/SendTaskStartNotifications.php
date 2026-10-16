<?php

namespace App\Console\Commands;

use App\Services\TaskNotificationScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send FCM notifications when tasks are starting now.
 *
 * This command runs every minute and sends push notifications with action buttons
 * (Complete, Skip) to users whose tasks are scheduled to start in the current minute.
 *
 * It uses cache to prevent duplicate notifications within a 5-minute window.
 */
class SendTaskStartNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:send-start-notifications
                            {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send FCM notifications when tasks are starting now';

    /**
     * Execute the console command.
     */
    public function handle(TaskNotificationScheduler $scheduler): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No notifications will be sent');
        }

        $this->info('Checking for tasks starting now...');

        try {
            $result = $scheduler->processTaskStartNotifications($isDryRun);

            // Display results
            $this->newLine();
            $this->info('Results:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Tasks Found', $result['tasks_found']],
                    ['Notifications Sent', $result['sent']],
                    ['Already Notified (skipped)', $result['skipped']],
                    ['Failed', $result['failed']],
                    ['No FCM Token', $result['no_token']],
                ]
            );

            // Show details if any failures
            if (!empty($result['errors'])) {
                $this->newLine();
                $this->warn('Errors:');
                foreach ($result['errors'] as $error) {
                    $this->error("  - Task {$error['task_id']}: {$error['message']}");
                }
            }

            // Show sent notifications in dry-run mode
            if ($isDryRun && !empty($result['dry_run_details'])) {
                $this->newLine();
                $this->info('Would send to:');
                foreach ($result['dry_run_details'] as $detail) {
                    $this->line("  - Task {$detail['task_id']}: {$detail['title']} (User: {$detail['user_id']})");
                }
            }

            Log::info('Task start notifications processed', [
                'sent' => $result['sent'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'dry_run' => $isDryRun,
            ]);

            return self::SUCCESS;

        } catch (\Exception $e) {
            Log::error('Failed to process task start notifications', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
