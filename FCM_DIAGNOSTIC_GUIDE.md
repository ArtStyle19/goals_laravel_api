# 🔧 Guía de Diagnóstico FCM - Notificaciones No Funcionan

## 📋 Lista de Verificación

### 1. **Verificar Configuración Firebase (Backend)**

```bash
# En el directorio del backend Laravel
cd /home/vicari/Downloads/PROJECTS/goals_laravel_api

# Verificar que el archivo de credenciales existe
ls -la storage/firebase/firebase-service-account.json

# Verificar que FCM está inicializado correctamente
php artisan tinker --execute="echo app(App\Services\FCMService::class)->isInitialized() ? 'FCM OK' : 'FCM ERROR';"
```

### 2. **Verificar Token del Usuario**

```bash
# En Laravel tinker
php artisan tinker

# Ejecutar:
$user = App\Models\User::find(1); // Cambia 1 por tu user ID
echo $user->fcm_token ? 'Token: ' . substr($user->fcm_token, 0, 20) . '...' : 'NO TOKEN';
exit;
```

### 3. **Enviar Notificación de Prueba Manual**

```bash
# Usando curl desde terminal
curl -X POST http://TU_API_URL/test-notification-with-actions \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","body":"Mensaje de prueba"}'
```

### 4. **Verificar Logs de Laravel**

```bash
# Ver logs en tiempo real
tail -f storage/logs/laravel.log | grep -i fcm

# O buscar en logs existentes
grep -i "fcm\|notification" storage/logs/laravel.log
```

### 5. **Verificar Configuración Flutter**

Asegúrate de que el archivo `android/app/google-services.json` existe y es válido:

```bash
cd /home/vicari/Downloads/PROJECTS/flutter_projects/goal_flutter_app

# Verificar que existe
ls -la android/app/google-services.json

# Verificar contenido (debe coincidir con tu proyecto Firebase)
cat android/app/google-services.json | grep project_id
```

### 6. **Recompilar App Flutter (IMPORTANTE)**

Después de todos los cambios, DEBES recompilar:

```bash
cd /home/vicari/Downloads/PROJECTS/flutter_projects/goal_flutter_app

# Limpiar build anterior
flutter clean

# Obtener dependencias
flutter pub get

# Recompilar para Android
flutter build apk --debug

# O instalar directamente en dispositivo
flutter run
```

### 7. **Verificar Logs de Flutter**

Conecta tu dispositivo y ejecuta:

```bash
flutter logs | grep -i fcm
```

Deberías ver mensajes como:

```
[FCM] Firebase initialized
[FCM] Token obtained: ...
[FCM] Token registered successfully with backend
[FCM] Service initialized successfully
```

### 8. **Problemas Comunes y Soluciones**

#### ❌ Problema: "No FCM token registered"

**Solución:**

1. Reinstala la app
2. Asegúrate de aceptar permisos de notificación
3. Verifica en Settings > FCM Test que el token se muestra

#### ❌ Problema: "Invalid token" o "registration-token-not-registered"

**Solución:**

1. El token FCM del dispositivo no es válido para tu proyecto Firebase
2. Verifica que `google-services.json` coincida con tu proyecto Firebase
3. Verifica que `firebase-service-account.json` en Laravel sea del mismo proyecto

#### ❌ Problema: Notificación llega pero botones no funcionan

**Solución:**

1. Asegúrate de que el backend envía mensajes **data-only** (sin `notification` payload)
2. Verifica que `show_actions: 'true'` está en el payload
3. Revisa logs de Flutter: `flutter logs | grep -i "action\|button"`

#### ❌ Problema: Notificación no llega cuando app está cerrada

**Solución:**

1. Verifica que el dispositivo tiene Google Play Services actualizado
2. En Android, ve a Configuración > Apps > Google Play Services > Actualizar
3. Verifica que no hay restricciones de batería para la app

### 9. **Verificar Paso a Paso**

#### Paso 1: Token se registra correctamente

```bash
# En Flutter logs deberías ver:
[FCM] Token obtained: fCMToken123...
[FCM] Token registered successfully with backend
```

#### Paso 2: Backend puede enviar notificaciones

```bash
# En Laravel logs deberías ver:
FCM data-only message sent successfully
```

#### Paso 3: Flutter recibe mensaje en background

```bash
# En Flutter logs deberías ver:
[FCM] ╔══════════════════════════════════════════╗
[FCM] ║  BACKGROUND MESSAGE RECEIVED             ║
[FCM] ╚══════════════════════════════════════════╝
[FCM] Data: {type: task_start, show_actions: true, ...}
[FCM] ✅ Local notification with actions shown from background
```

#### Paso 4: Al presionar botón se ejecuta acción

```bash
# En Flutter logs deberías ver:
[FCM] Background notification response: action=complete_task
[FCM] Stored pending action: complete_task for task 123
[FCM] Processing pending action: complete_task for task 123
[FCM] ✅ Task 123 COMPLETED successfully via notification
```

### 10. **Comandos Útiles para Debug**

```bash
# Verificar estado de FCM en backend
curl -H "Authorization: Bearer TU_TOKEN" \
  http://TU_API_URL/notification-status

# Enviar notificación de prueba simple
curl -X POST -H "Authorization: Bearer TU_TOKEN" \
  -H "Content-Type: application/json" \
  http://TU_API_URL/test-notification \
  -d '{"title":"Test Simple","body":"Funciona?"}'

# Enviar notificación con botones
curl -X POST -H "Authorization: Bearer TU_TOKEN" \
  -H "Content-Type: application/json" \
  http://TU_API_URL/test-notification-with-actions \
  -d '{"title":"Test Botones","body":"Presiona Completar"}'
```

## 🚨 Si Nada Funciona

1. **Elimina y reinstala la app** en tu dispositivo
2. **Limpia caché de Flutter**: `flutter clean`
3. **Verifica que el proyecto Firebase es el correcto**:
    - Ve a https://console.firebase.google.com/
    - Selecciona `goal-project-70cf2`
    - Ve a Configuración del proyecto > General
    - Verifica que el ID del proyecto coincide

4. **Verifica que el token FCM es válido**:

    ```bash
    # En Firebase Console > Cloud Messaging > Send test message
    # Pega el token del dispositivo y envía una notificación
    ```

5. **Verifica permisos del dispositivo**:
    - Configuración > Apps > Productivity > Notificaciones
    - Asegúrate de que están habilitadas

## 📞 Información Necesaria para Soporte

Si necesitas más ayuda, proporciona:

1. **Logs de Flutter** al iniciar la app:

    ```bash
    flutter logs | grep -i "fcm\|firebase\|error" > flutter_logs.txt
    ```

2. **Logs de Laravel** al enviar notificación:

    ```bash
    tail -100 storage/logs/laravel.log | grep -i fcm > laravel_logs.txt
    ```

3. **Token FCM del dispositivo** (Settings > FCM Test en la app)

4. **Versión de Android** del dispositivo

5. **¿La notificación llega pero los botones no funcionan?** o **¿No llega nada?**
