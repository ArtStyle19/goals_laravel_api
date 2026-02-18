<?php

namespace App\Http\Controllers;

use App\Services\FCMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Notification Controller
 *
 * Handles FCM token management and test notifications.
 */
class NotificationController extends Controller
{
    private FCMService $fcmService;

    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    /**
     * Store or update FCM token for authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeToken(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'fcm_token' => 'required|string|min:100|max:500',
            ]);

            $user = $request->user();

            // Only update if token changed
            if ($user->fcm_token !== $validated['fcm_token']) {
                $user->fcm_token = $validated['fcm_token'];
                $user->save();

                Log::info('FCM token updated', [
                    'user_id' => $user->id,
                    'token_preview' => substr($validated['fcm_token'], 0, 20) . '...',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'FCM token registered successfully',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to store FCM token', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to register token',
            ], 500);
        }
    }

    /**
     * Remove FCM token (logout/unregister)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function removeToken(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if ($user->fcm_token) {
                $user->fcm_token = null;
                $user->save();

                Log::info('FCM token removed', [
                    'user_id' => $user->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'FCM token removed successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to remove FCM token', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove token',
            ], 500);
        }
    }

    /**
     * Send test notification to authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendTest(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user->fcm_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No FCM token registered. Please register your device first.',
                ], 400);
            }

            $title = $request->input('title', '🧪 Test de Notificación');
            $body = $request->input('body', 'Esta es una notificación de prueba desde el backend Laravel');

            $data = [
                'type' => 'test_notification',
                'timestamp' => now()->toIso8601String(),
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ];

            $result = $this->fcmService->sendToToken(
                $user->fcm_token,
                $title,
                $body,
                $data
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification sent successfully',
                ]);
            }

            // Handle invalid token
            if (isset($result['invalid_token']) && $result['invalid_token']) {
                $user->fcm_token = null;
                $user->save();

                return response()->json([
                    'success' => false,
                    'message' => 'FCM token is invalid and has been removed. Please re-register.',
                ], 400);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to send test notification', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send notification',
            ], 500);
        }
    }

    /**
     * Get FCM service status
     *
     * @return JsonResponse
     */
    public function status(): JsonResponse
    {
        $isInitialized = $this->fcmService->isInitialized();

        return response()->json([
            'success' => true,
            'data' => [
                'fcm_initialized' => $isInitialized,
                'credentials_exist' => file_exists(config('firebase.credentials.file')),
            ],
        ]);
    }
}
