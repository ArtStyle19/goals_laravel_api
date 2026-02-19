# 🔧 Solución de Problemas FCM - Backend Laravel

## ✅ Estado Actual del Backend

Todos los tests pasan (16/16). El backend está correctamente implementado.

## 🐛 Problemas Reportados y Soluciones

### 1. Click en notificación no abre la app

**Causa:** Falta configuración en Flutter para manejar deep links desde notificaciones FCM.

**Solución Backend (YA IMPLEMENTADA):**

- ✅ Payload FCM ahora incluye `click_action: FLUTTER_NOTIFICATION_CLICK`
- ✅ Configuración Android con `channel_id` y `priority: high`
- ✅ Configuración iOS con `category: TASK_ACTIONS`
- ✅ Deep link route incluido: `route: /task/{id}`

**Solución Flutter (NECESITA IMPLEMENTARSE):**

```dart
// En main.dart o app.dart, configurar manejo de notificaciones:

// 1. Manejar cuando la app se abre desde una notificación
FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
  final route = message.data['route'];
  if (route != null) {
    // Navegar a la ruta usando GoRouter
    router.go(route);
  }
});

// 2. Manejar click en notificación cuando app está en background
FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);

// 3. Configurar local notifications con click_action
FlutterLocalNotificationsPlugin().initialize(
  initializationSettings,
  onDidReceiveNotificationResponse: (NotificationResponse response) {
    final payload = response.payload;
    // Parsear payload y navegar
  },
);
```

---

### 2. Botón "Completar" no funciona

**Causa:** Flutter no está llamando al endpoint backend cuando se presiona el botón de acción.

**Solución Backend (YA IMPLEMENTADA):**

- ✅ Nuevos endpoints creados:
    - `POST /api/tasks/{id}/complete-from-notification`
    - `POST /api/tasks/{id}/skip-from-notification`
- ✅ Payload FCM incluye información de acciones:
    ```json
    {
        "action_1_id": "complete_task",
        "action_1_title": "Completar",
        "action_2_id": "skip_task",
        "action_2_title": "Omitir"
    }
    ```

**Solución Flutter (NECESITA IMPLEMENTARSE):**

```dart
// Configurar acciones de notificación local:
const AndroidNotificationAction completeAction = AndroidNotificationAction(
  'complete_task',
  'Completar',
  showsUserInterface: true,
);

const AndroidNotificationAction skipAction = AndroidNotificationAction(
  'skip_task',
  'Omitir',
  showsUserInterface: true,
);

// Manejar acción cuando se presiona botón:
void handleNotificationAction(String actionId, Map<String, dynamic> data) {
  final taskId = data['task_id'];

  switch (actionId) {
    case 'complete_task':
      // Llamar al endpoint
      await apiClient.post('/tasks/$taskId/complete-from-notification');
      break;
    case 'skip_task':
      await apiClient.post('/tasks/$taskId/skip-from-notification');
      break;
  }
}
```

---

### 3. Notificaciones no se envían automáticamente

**Causa:** El cron job de Laravel no está configurado en el servidor.

**Solución (NECESITA CONFIGURARSE EN SERVIDOR):**

**Opción A: Cron Job en Servidor Linux**

```bash
# Editar crontab
crontab -e

# Agregar línea (ejecuta cada minuto):
* * * * * cd /ruta/a/laravel && php artisan schedule:run >> /dev/null 2>&1
```

**Opción B: Usar Docker Compose**

```yaml
# En docker-compose.yml, agregar:
services:
    scheduler:
        image: your-php-image
        command: >
            sh -c "while true; do
              php artisan schedule:run;
              sleep 60;
            done"
        volumes:
            - .:/var/www
```

**Opción C: Worker de Colas (para producción)**

```bash
# Usar Laravel Queue para procesar notificaciones
php artisan queue:work --queue=notifications
```

**Verificar que el schedule está configurado:**

```bash
# Ver tareas programadas
php artisan schedule:list

# Probar ejecución manual
php artisan tasks:send-start-notifications --dry-run
```

---

## 📋 Resumen de Cambios Backend

### Archivos Modificados:

1. **`app/Services/FCMService.php`**
    - Agregado `sendToTokenWithPlatformConfig()` con configuración Android/iOS
    - Actualizado `sendTaskNotificationWithActions()` con payload mejorado
    - Incluye `route`, `click_action`, y datos de acciones individuales

2. **`app/Http/Controllers/TaskController.php`**
    - Agregado `completeFromNotification()` endpoint
    - Agregado `skipFromNotification()` endpoint
    - Documentación OpenAPI incluida

3. **`routes/api.php`**
    - Agregadas rutas para nuevos endpoints de notificación

### Endpoints Disponibles:

| Método | Endpoint                                     | Descripción                     |
| ------ | -------------------------------------------- | ------------------------------- |
| POST   | `/api/tasks/{id}/complete-from-notification` | Completar desde notificación    |
| POST   | `/api/tasks/{id}/skip-from-notification`     | Saltar desde notificación       |
| POST   | `/api/test-notification-with-actions`        | Probar notificación con botones |

---

## 🚀 Próximos Pasos

1. **Flutter:** Implementar manejo de deep links desde notificaciones
2. **Flutter:** Implementar llamadas a endpoints cuando se presionan botones de acción
3. **Servidor:** Configurar cron job para envío automático de notificaciones

El backend está listo y funcionando. Los problemas reportados son de implementación en Flutter y configuración de servidor.
