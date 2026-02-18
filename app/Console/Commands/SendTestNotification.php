<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FCMService;
use Illuminate\Console\Command;

/**
 * Send a test notification to a specific user
 *
 * Usage:
 *   sail artisan app:send-test-notification {user} {--title=} {--body=}
 *
 * Examples:
 *   sail artisan app:send-test-notification 1
 *   sail artisan app:send-test-notification user@email.com --title="Hola" --body="Mensaje de prueba"
 *   sail artisan app:send-test-notification 1 --title="Test" --body="App cerrada"
 */
class SendTestNotification extends Command
{
    protected $signature = 'app:send-test-notification
                            {user : User ID or email}
                            {--title=🧪 Notificación de Prueba : Notification title}
                            {--body=Esta es una prueba de FCM : Notification body}';

    protected $description = 'Send a test FCM notification to a specific user';

    private FCMService $fcmService;

    public function __construct(FCMService $fcmService)
    {
        parent::__construct();
        $this->fcmService = $fcmService;
    }

    public function handle(): int
    {
        // Check FCM is initialized
        if (!$this->fcmService->isInitialized()) {
            $this->error('❌ FCM service is not initialized');
            $this->error('   Check that firebase credentials file exists at: ' . config('firebase.credentials.file'));
            return 1;
        }

        // Find user
        $userIdentifier = $this->argument('user');
        $user = is_numeric($userIdentifier)
            ? User::find($userIdentifier)
            : User::where('email', $userIdentifier)->first();

        if (!$user) {
            $this->error("❌ User not found: {$userIdentifier}");
            return 1;
        }

        $this->info("📱 User: {$user->name} (ID: {$user->id})");

        // Check FCM token
        if (!$user->fcm_token) {
            $this->error('❌ User has no FCM token registered');
            $this->warn('   Ask the user to open the app and enable notifications first');
            return 1;
        }

        $this->info('🔑 FCM Token: ' . substr($user->fcm_token, 0, 30) . '...');

        // Prepare notification
        $title = $this->option('title');
        $body = $this->option('body');

        $this->info('📝 Title: ' . $title);
        $this->info('📝 Body: ' . $body);

        // Send notification
        $this->info('🚀 Sending notification...');

        $result = $this->fcmService->sendToToken(
            $user->fcm_token,
            $title,
            $body,
            [
                'type' => 'test_notification',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'timestamp' => now()->toIso8601String(),
                'test' => true,
            ]
        );

        if ($result['success']) {
            $this->info('✅ Notification sent successfully!');
            $this->newLine();
            $this->info('📲 The user should receive the notification even if the app is closed');
            $this->info('   (as long as they have notification permissions enabled)');

            // Show additional info
            if (isset($result['message_id'])) {
                $this->info('   Message ID: ' . $result['message_id']);
            }

            return 0;
        }

        // Handle errors
        $this->error('❌ Failed to send notification');
        $this->error('   Reason: ' . $result['message']);

        if (isset($result['invalid_token']) && $result['invalid_token']) {
            $this->warn('   The FCM token is invalid and has been cleared');
            $user->fcm_token = null;
            $user->save();
            $this->warn('   User needs to re-register their device');
        }

        return 1;
    }
}
