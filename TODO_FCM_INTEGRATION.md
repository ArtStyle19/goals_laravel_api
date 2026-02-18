# FCM Integration - TODO List

## ✅ COMPLETED

### Backend (Laravel)

- [x] Install kreait/firebase-php package
- [x] Create FCMService class with sendToToken(), sendToTopic(), sendTaskReminders()
- [x] Create config/firebase.php with credentials path
- [x] Create NotificationController with endpoints:
    - POST /api/user/fcm-token (register token)
    - DELETE /api/user/fcm-token (remove token)
    - POST /api/test-notification (send test)
    - GET /api/notification-status (check status)
- [x] Add routes to routes/api.php
- [x] Create SendTaskReminders command (scheduled)
- [x] Create SendTestNotification command (manual)
- [x] Schedule command to run every minute in routes/console.php
- [x] Fix kreait/firebase-php v8.x API changes (CloudMessage::new())
- [x] Fix Docker path issue for Firebase credentials
- [x] **TESTS**: SendTaskRemindersTest (8 tests)
- [x] **TESTS**: FCMServiceTest (4 tests)
- [x] **TESTS**: NotificationControllerTest (8 tests)
- [x] **TESTS**: SendTestNotificationCommandTest (7 tests)

### Frontend (Flutter)

- [x] Add firebase_core and firebase_messaging dependencies
- [x] Configure AndroidManifest.xml with permissions
- [x] Add google-services.json to android/app/
- [x] Configure android/build.gradle with Firebase plugin
- [x] Configure android/app/build.gradle with Firebase dependencies
- [x] Create FCMRemoteDataSource for backend API calls
- [x] Create FCMService for Firebase operations
- [x] Update injection_container.dart with FCM providers
- [x] Update main.dart to initialize FCM
- [x] Add FCM test section to Settings page
- [x] Fix field name from 'token' to 'fcm_token' for Laravel validation

## 🎯 Summary

**Total Tests**: 27 tests, 79 assertions - ALL PASSING ✅

### Test Coverage:

1. **SendTaskRemindersTest** (8 tests) - Scheduled reminders 15 min before tasks
2. **FCMServiceTest** (4 tests) - FCM service initialization and error handling
3. **NotificationControllerTest** (8 tests) - API endpoints for token management
4. **SendTestNotificationCommandTest** (7 tests) - Manual test notification command

## 🚀 Manual Test Notification Command

### Enviar notificación de prueba (incluso con app cerrada):

```bash
# Usando Sail (desarrollo)
./vendor/bin/sail artisan app:send-test-notification {user_id}

# Ejemplos:
./vendor/bin/sail artisan app:send-test-notification 1
./vendor/bin/sail artisan app:send-test-notification user@email.com
./vendor/bin/sail artisan app:send-test-notification 1 --title="Hola" --body="App cerrada"
./vendor/bin/sail artisan app:send-test-notification 1 --title="🚀 Test" --body="¿Funciona?"
```

### Opciones disponibles:

- `--title` - Título de la notificación (default: "🧪 Notificación de Prueba")
- `--body` - Mensaje de la notificación (default: "Esta es una prueba de FCM")

### El comando verifica:

1. ✅ FCM está inicializado
2. ✅ Usuario existe
3. ✅ Usuario tiene token FCM registrado
4. ✅ Envía notificación push
5. ✅ Maneja errores de token inválido

## 📋 Next Steps (Optional)

1. **Production Deployment**:
    - Set up cron job: `* * * * * cd /path && php artisan schedule:run`
    - Ensure Firebase credentials file is accessible

2. **iOS Support**:
    - Add Firebase iOS configuration
    - Configure APNs certificates

3. **Advanced Features**:
    - Notification topics for broadcast messages
    - Rich notifications with images
    - Action buttons in notifications
