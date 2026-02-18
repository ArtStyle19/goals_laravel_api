# Implementación FCM Backend Laravel

## Pasos a Completar

### 1. Instalar Dependencias

- [ ] Instalar kreait/firebase-php

### 2. Configuración

- [ ] Crear config/firebase.php
- [ ] Verificar .env tiene las variables correctas

### 3. Base de Datos

- [ ] Crear migración para agregar fcm_token a users
- [ ] Ejecutar migración

### 4. Modelo User

- [ ] Agregar fcm_token a fillable
- [ ] Agregar método routeNotificationForFcm()

### 5. Servicios

- [ ] Crear app/Services/FCMService.php
- [ ] Implementar envío a usuarios individuales
- [ ] Implementar envío masivo
- [ ] Implementar envío por topic

### 6. Controllers

- [ ] Crear app/Http/Controllers/NotificationController.php
- [ ] Método para guardar token
- [ ] Método para eliminar token
- [ ] Método para probar notificación

### 7. Rutas API

- [ ] Agregar rutas en routes/api.php
- [ ] POST /api/user/fcm-token
- [ ] DELETE /api/user/fcm-token
- [ ] POST /api/test-notification

### 8. Comandos Programados

- [ ] Crear app/Console/Commands/SendTaskReminders.php
- [ ] Programar en routes/console.php

### 9. Testing

- [ ] Verificar build exitoso
- [ ] Verificar rutas registradas

## Criterios de Calidad

- Código mantenible y escalable
- Sin redundancias
- Sigue arquitectura Laravel (Service Layer, Repository Pattern)
- Manejo de errores robusto
- Logging apropiado
