# FCM Implementation Summary

## Overview

Firebase Cloud Messaging (FCM) has been successfully implemented for the Productivity Planner app with full support for:

- Push notifications from Laravel backend to Flutter app
- Action buttons (Complete, Skip) in notifications
- Backend token management
- Scheduled task notifications

## Backend (Laravel)

### Files Created/Modified

#### 1. `app/Services/FCMService.php`

- **Purpose**: Centralized FCM service for sending notifications
- **Key Features**:
    - Send notifications to individual tokens
    - Send to topics
    - Token subscription management
    - Task notifications with action buttons
    - Data-only messages for background handling
    - Hybrid messages (notification + data) for action buttons

#### 2. `app/Http/Controllers/NotificationController.php`

- **Purpose**: API endpoints for FCM token management
- **Endpoints**:
    - `POST /api/user/fcm-token` - Register device token
    - `DELETE /api/user/fcm-token` - Remove device token
    - `POST /api/test-notification` - Send test notification
    - `POST /api/test-notification/actions` - Send test with action buttons
    - `GET /api/notification-status` - Check FCM service status

#### 3. `app/Console/Commands/SendTaskStartNotifications.php`

- **Purpose**: Send notifications when tasks start
- **Features**:
    - Finds tasks starting within the current minute
    - Sends notifications with Complete/Skip buttons
    - Tracks sent notifications to avoid duplicates
    - Dry-run mode for testing

#### 4. `app/Console/Commands/SendTaskReminders.php`

- **Purpose**: Send reminder notifications 15 minutes before tasks
- **Features**:
    - Finds tasks starting in 15 minutes
    - Sends reminder notifications
    - Tracks sent reminders

#### 5. `routes/console.php`

- **Purpose**: Schedule automatic notification commands
- **Schedule**:
    - Task start notifications: Every minute
    - Task reminders: Every minute

### Database Migration

- **File**: `database/migrations/2026_02_18_184334_add_fcm_token_to_users_table.php`
- **Purpose**: Adds `fcm_token` column to users table

## Frontend (Flutter)

### Files Created/Modified

#### 1. `lib/core/services/fcm_service.dart`

- **Purpose**: Handle FCM in Flutter app
- **Key Features**:
    - Initialize Firebase and FCM
    - Request notification permissions
    - Get and refresh FCM tokens
    - Handle foreground, background, and terminated messages
    - Display local notifications with action buttons
    - Handle Complete/Skip actions from notifications
    - Sync tokens with backend

#### 2. `lib/core/services/fcm_remote_datasource.dart`

- **Purpose**: Backend API communication for FCM
- **Methods**:
    - `registerToken()` - Register device with backend
    - `removeToken()` - Remove device on logout
    - `sendTestNotification()` - Request test notification
    - `sendTestNotificationWithActions()` - Request test with buttons
    - `getStatus()` - Check backend FCM status

#### 3. `lib/app/di/injection_container.dart`

- **Purpose**: Dependency injection for FCM services
- **Providers**:
    - `fcmServiceProvider` - Singleton FCM service
    - `fcmRemoteDataSourceProvider` - API data source

#### 4. `lib/main.dart`

- **Purpose**: Initialize FCM on app startup
- **Features**:
    - Initialize FCM service
    - Set up remote data source
    - Configure notification callbacks

#### 5. `lib/features/settings/presentation/pages/settings_page.dart`

- **Purpose**: UI for FCM testing
- **Features**:
    - Test notification button
    - Test notification with action buttons
    - FCM service status display
    - Token registration status

## How It Works

### Notification Flow

1. **Task Scheduled**: When a task is scheduled in the database
2. **Cron Job**: Laravel's scheduler runs every minute
3. **Find Tasks**: Command finds tasks starting now or in 15 minutes
4. **Send FCM**: Backend sends FCM message with:
    - Notification payload (title, body)
    - Data payload (task_id, action buttons config)
5. **Device Receives**: Flutter app receives message
6. **Display**: System shows notification with action buttons
7. **User Action**: User taps Complete or Skip
8. **Handle Action**: Flutter processes the action and updates task status

### Message Types

#### 1. Standard Notification

```json
{
    "notification": {
        "title": "📋 Es hora de tu sesión",
        "body": "Task title está programada ahora"
    },
    "data": {
        "type": "task_start",
        "task_id": "123",
        "click_action": "FLUTTER_NOTIFICATION_CLICK"
    }
}
```

#### 2. Notification with Actions

```json
{
    "notification": {
        "title": "📋 Es hora de tu sesión",
        "body": "Task title está programada ahora"
    },
    "data": {
        "type": "task_start",
        "task_id": "123",
        "show_actions": "true",
        "actions": "[{\"id\":\"complete_task\",\"title\":\"Completar\"},{\"id\":\"skip_task\",\"title\":\"Omitir\"}]",
        "click_action": "FLUTTER_NOTIFICATION_CLICK"
    }
}
```

## Testing

### Backend Commands

```bash
# Send test notification
./vendor/bin/sail artisan fcm:send-test <user_id>

# Send task start notifications (dry-run)
./vendor/bin/sail artisan tasks:send-start-notifications --dry-run

# Send task start notifications (actual)
./vendor/bin/sail artisan tasks:send-start-notifications

# Send task reminders (dry-run)
./vendor/bin/sail artisan tasks:send-reminders --dry-run
```

### API Endpoints

```bash
# Register FCM token
curl -X POST /api/user/fcm-token \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"fcm_token": "your_fcm_token_here"}'

# Send test notification
curl -X POST /api/test-notification \
  -H "Authorization: Bearer $TOKEN"

# Send test with action buttons
curl -X POST /api/test-notification/actions \
  -H "Authorization: Bearer $TOKEN"
```

## Configuration

### Android

- Permissions in `AndroidManifest.xml`:
    - `POST_NOTIFICATIONS`
    - `VIBRATE`
    - `SCHEDULE_EXACT_ALARM`
    - `USE_EXACT_ALARM`

### iOS

- Push notifications capability enabled
- Background modes: Remote notifications

## Architecture Principles

1. **Clean Architecture**: Separation of concerns with Service, DataSource, and Controller layers
2. **Single Responsibility**: Each class has one clear purpose
3. **Dependency Injection**: Uses Riverpod for Flutter, Laravel's container for backend
4. **Error Handling**: Comprehensive try-catch blocks with logging
5. **Scalability**: Supports batch sending, topic subscriptions
6. **Maintainability**: Well-documented code with clear method names

## Next Steps

1. **Test on Real Device**: Use the Settings page in Flutter app to test notifications
2. **Verify Action Buttons**: Tap Complete/Skip and verify task status updates
3. **Check Background Behavior**: Test with app in background and terminated states
4. **Monitor Logs**: Check both Laravel logs and Flutter logs for any issues
5. **Production**: Ensure cron jobs are running on production server

## Troubleshooting

### Common Issues

1. **"Invalid JSON payload received"**: Check FCM message structure, remove invalid fields
2. **Notifications not received**: Verify FCM token is registered, check device permissions
3. **Action buttons not working**: Ensure `show_actions` is in data payload
4. **Background handling**: Verify `FirebaseMessaging.onBackgroundMessage` is set

### Logs

- **Laravel**: `storage/logs/laravel.log`
- **Flutter**: Run with `flutter run` and check console output

## Security Considerations

1. FCM tokens are stored per user in the database
2. Tokens are removed on logout
3. Invalid tokens are automatically cleaned up
4. API endpoints require authentication
