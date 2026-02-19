<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Firebase Cloud Messaging Service
 *
 * Handles all FCM operations including sending notifications to devices,
 * topics, and handling token management.
 */
class FCMService
{
    private ?Messaging $messaging = null;

    public function __construct()
    {
        $this->initialize();
    }

    /**
     * Initialize Firebase Messaging instance
     */
    private function initialize(): void
    {
        try {
            $credentialsPath = config('firebase.credentials.file');

            if (!file_exists($credentialsPath)) {
                Log::error('Firebase credentials file not found', [
                    'path' => $credentialsPath,
                ]);
                return;
            }

            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $this->messaging = $factory->createMessaging();

            Log::info('FCM Service initialized successfully');
        } catch (\Exception $e) {
            Log::error('Failed to initialize FCM Service', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convert all data values to strings for FCM compatibility
     *
     * @param array $data
     * @return array
     */
    private function convertDataToStrings(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $result[$key] = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $result[$key] = '';
            } elseif (is_array($value) || is_object($value)) {
                $result[$key] = json_encode($value);
            } else {
                $result[$key] = (string) $value;
            }
        }
        return $result;
    }

    /**
     * Send notification to a specific device by FCM token
     *
     * @param string $token FCM device token
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data payload
     * @return array Result with success status and message
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM not initialized',
            ];
        }

        try {
            $notification = Notification::create($title, $body);

            // Convert all data values to strings for FCM compatibility
            $stringData = $this->convertDataToStrings($data);

            // Use CloudMessage::new() for kreait/firebase-php v8.x
            $message = CloudMessage::new()
                ->toToken($token)
                ->withNotification($notification)
                ->withData($stringData);

            $this->messaging->send($message);

            Log::info('FCM notification sent successfully', [
                'token' => substr($token, 0, 20) . '...',
                'title' => $title,
            ]);

            return [
                'success' => true,
                'message' => 'Notification sent successfully',
            ];
        } catch (MessagingException $e) {
            Log::error('Failed to send FCM notification', [
                'token' => substr($token, 0, 20) . '...',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            // Handle invalid token
            if ($e->getCode() === 404 || str_contains($e->getMessage(), 'registration-token-not-registered')) {
                return [
                    'success' => false,
                    'message' => 'Invalid token',
                    'invalid_token' => true,
                ];
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('Unexpected error sending FCM notification', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send notification to multiple devices
     *
     * @param array $tokens Array of FCM tokens
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data payload
     * @return array Result with success count and failed tokens
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM not initialized',
            ];
        }

        $results = [
            'success_count' => 0,
            'failure_count' => 0,
            'invalid_tokens' => [],
        ];

        foreach ($tokens as $token) {
            $result = $this->sendToToken($token, $title, $body, $data);

            if ($result['success']) {
                $results['success_count']++;
            } else {
                $results['failure_count']++;
                if (isset($result['invalid_token']) && $result['invalid_token']) {
                    $results['invalid_tokens'][] = $token;
                }
            }
        }

        Log::info('Batch FCM notification completed', [
            'total' => count($tokens),
            'success' => $results['success_count'],
            'failed' => $results['failure_count'],
        ]);

        return $results;
    }

    /**
     * Send notification to a topic
     *
     * @param string $topic Topic name
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data payload
     * @return array Result with success status
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM not initialized',
            ];
        }

        try {
            $notification = Notification::create($title, $body);

            // Convert all data values to strings for FCM compatibility
            $stringData = $this->convertDataToStrings($data);

            // Use CloudMessage::new() for kreait/firebase-php v8.x
            $message = CloudMessage::new()
                ->toTopic($topic)
                ->withNotification($notification)
                ->withData($stringData);

            $this->messaging->send($message);

            Log::info('FCM topic notification sent', [
                'topic' => $topic,
                'title' => $title,
            ]);

            return [
                'success' => true,
                'message' => 'Topic notification sent successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send FCM topic notification', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Subscribe tokens to a topic
     *
     * @param array $tokens FCM tokens to subscribe
     * @param string $topic Topic name
     * @return array Result with success status
     */
    public function subscribeToTopic(array $tokens, string $topic): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM not initialized',
            ];
        }

        try {
            $this->messaging->subscribeToTopic($topic, $tokens);

            Log::info('Tokens subscribed to topic', [
                'topic' => $topic,
                'count' => count($tokens),
            ]);

            return [
                'success' => true,
                'message' => 'Subscribed successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to subscribe tokens to topic', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Unsubscribe tokens from a topic
     *
     * @param array $tokens FCM tokens to unsubscribe
     * @param string $topic Topic name
     * @return array Result with success status
     */
    public function unsubscribeFromTopic(array $tokens, string $topic): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM not initialized',
            ];
        }

        try {
            $this->messaging->unsubscribeFromTopic($topic, $tokens);

            Log::info('Tokens unsubscribed from topic', [
                'topic' => $topic,
                'count' => count($tokens),
            ]);

            return [
                'success' => true,
                'message' => 'Unsubscribed successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to unsubscribe tokens from topic', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send task reminder notification to a user
     *
     * @param \App\Models\User $user
     * @param \App\Models\Task $task
     * @return array
     */
    public function sendTaskReminder($user, $task): array
    {
        if (!$user->fcm_token) {
            return [
                'success' => false,
                'message' => 'User has no FCM token',
            ];
        }

        $title = '⏰ Próxima sesión';
        $body = "{$task->title} comienza en 15 minutos";

        $data = [
            'type' => 'task_reminder',
            'task_id' => (string) $task->id,
            'goal_id' => (string) $task->goal_id,
            'scheduled_at' => (string) $task->scheduled_at,
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ];

        return $this->sendToToken($user->fcm_token, $title, $body, $data);
    }

    /**
     * Send task notification with action buttons (Complete, Skip)
     *
     * This method sends a HYBRID message with both notification and data payload.
     * The notification payload ensures the message is displayed automatically
     * by FCM in all app states. The data payload contains action button info
     * that Flutter uses to handle button presses.
     *
     * @param \App\Models\User $user
     * @param \App\Models\Task $task
     * @param string $notificationType Type: 'task_start', 'task_reminder', 'task_overdue'
     * @return array
     */
    public function sendTaskNotificationWithActions($user, $task, string $notificationType = 'task_start'): array
    {
        if (!$user->fcm_token) {
            return [
                'success' => false,
                'message' => 'User has no FCM token',
            ];
        }

        // Determine title and body based on notification type
        switch ($notificationType) {
            case 'task_reminder':
                $title = '⏰ Próxima sesión';
                $body = "{$task->title} comienza en 15 minutos";
                break;
            case 'task_overdue':
                $title = '⚠️ Tarea vencida';
                $body = "{$task->title} debería haber comenzado";
                break;
            case 'task_start':
            default:
                $title = '📋 Es hora de tu sesión';
                $body = "{$task->title} está programada ahora";
                break;
        }

        // Build action buttons configuration for Flutter
        $actions = [
            [
                'id' => 'complete_task',
                'title' => 'Completar',
                'action' => 'complete',
            ],
            [
                'id' => 'skip_task',
                'title' => 'Omitir',
                'action' => 'skip',
            ],
        ];

        // Data payload for Flutter - all values must be strings
        $data = [
            'type' => $notificationType,
            'task_id' => (string) $task->id,
            'goal_id' => (string) $task->goal_id,
            'goal_title' => $task->goal->title ?? '',
            'task_title' => $task->title,
            'scheduled_at' => (string) $task->scheduled_at,
            'end_at' => (string) ($task->end_at ?? ''),
            'duration_minutes' => (string) ($task->duration_minutes ?? '60'),
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'route' => '/task/' . $task->id,
            'show_actions' => 'true',
            'actions' => json_encode($actions),
            'action_count' => (string) count($actions),
            // Individual action data for easier parsing
            'action_1_id' => 'complete_task',
            'action_1_title' => 'Completar',
            'action_2_id' => 'skip_task',
            'action_2_title' => 'Omitir',
        ];

        // Convert all data values to strings for FCM compatibility
        $stringData = $this->convertDataToStrings($data);

        // Send as HYBRID message (notification + data payload)
        // This ensures FCM displays the notification automatically
        // while Flutter can still handle action buttons via data payload
        return $this->sendToTokenWithActions($user->fcm_token, $title, $body, $stringData);
    }

    /**
     * Send a data-only FCM message (no notification payload).
     *
     * Data-only messages are handled by the Flutter app which then
     * displays a local notification with action buttons. This ensures
     * consistent behavior across all app states.
     *
     * @param string $token
     * @param array $data
     * @return array
     */
    public function sendDataOnlyMessage(string $token, array $data): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM not initialized',
            ];
        }

        try {
            // Build Android config with high priority for data messages
            $androidConfig = [
                'priority' => 'high',
                'direct_boot_ok' => true,
            ];

            // Build APNS config for iOS
            $apnsConfig = [
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'content-available' => 1, // Silent notification for iOS
                    ],
                ],
            ];

            // Create data-only message (no notification payload)
            $message = CloudMessage::new()
                ->toToken($token)
                ->withData($data)
                ->withAndroidConfig($androidConfig)
                ->withApnsConfig($apnsConfig);

            $this->messaging->send($message);

            Log::info('FCM data-only message sent successfully', [
                'token' => substr($token, 0, 20) . '...',
                'data_keys' => array_keys($data),
            ]);

            return [
                'success' => true,
                'message' => 'Data-only message sent successfully',
            ];
        } catch (MessagingException $e) {
            Log::error('Failed to send FCM data-only message', [
                'token' => substr($token, 0, 20) . '...',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            if ($e->getCode() === 404 || str_contains($e->getMessage(), 'registration-token-not-registered')) {
                return [
                    'success' => false,
                    'message' => 'Invalid token',
                    'invalid_token' => true,
                ];
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('Unexpected error sending FCM data-only message', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send notification with action buttons support.
     * Uses high priority and includes data payload for Flutter action handling.
     *
     * @param string $token
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    private function sendToTokenWithActions(string $token, string $title, string $body, array $data): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM not initialized',
            ];
        }

        try {
            $notification = Notification::create($title, $body);

            // Android config with high priority and notification channel
            $androidConfig = [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'fcm_task_actions',
                    'default_sound' => true,
                    'default_vibrate_timings' => true,
                ],
            ];

            // APNS config for iOS
            $apnsConfig = [
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'alert' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'sound' => 'default',
                        'badge' => 1,
                        'category' => 'task_actions',
                    ],
                ],
            ];

            // Build message with notification + data payload
            $message = CloudMessage::new()
                ->toToken($token)
                ->withNotification($notification)
                ->withData($data)
                ->withAndroidConfig($androidConfig)
                ->withApnsConfig($apnsConfig);

            $this->messaging->send($message);

            Log::info('FCM notification with actions sent successfully', [
                'token' => substr($token, 0, 20) . '...',
                'title' => $title,
                'task_id' => $data['task_id'] ?? 'unknown',
            ]);

            return [
                'success' => true,
                'message' => 'Notification sent successfully',
            ];
        } catch (MessagingException $e) {
            Log::error('Failed to send FCM notification with actions', [
                'token' => substr($token, 0, 20) . '...',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            if ($e->getCode() === 404 || str_contains($e->getMessage(), 'registration-token-not-registered')) {
                return [
                    'success' => false,
                    'message' => 'Invalid token',
                    'invalid_token' => true,
                ];
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('Unexpected error sending FCM notification with actions', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send immediate task start notification with complete button
     *
     * @param \App\Models\User $user
     * @param \App\Models\Task $task
     * @return array
     */
    public function sendTaskStartNotification($user, $task): array
    {
        return $this->sendTaskNotificationWithActions($user, $task, 'task_start');
    }

    /**
     * Validate if FCM service is properly initialized
     *
     * @return bool
     */
    public function isInitialized(): bool
    {
        return $this->messaging !== null;
    }
}
