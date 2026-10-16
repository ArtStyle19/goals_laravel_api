<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==================== PUBLIC ROUTES ====================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google', [AuthController::class, 'loginWithGoogle']);

// ==================== PROTECTED ROUTES ====================
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Goals
    Route::get('/goals', [GoalController::class, 'index']);
    Route::post('/goals', [GoalController::class, 'store']);
    Route::get('/goals/{goal}', [GoalController::class, 'show']);
    Route::put('/goals/{goal}', [GoalController::class, 'update']);
    Route::delete('/goals/{goal}', [GoalController::class, 'destroy']);

    // Goal Actions
    Route::post('/goals/{goal}/tasks', [GoalController::class, 'addTasks']);
    Route::post('/goals/{goal}/generate', [GoalController::class, 'generate']);
    Route::get('/goals/{goal}/stats', [GoalController::class, 'stats']);
    Route::post('/goals/{goal}/complete', [GoalController::class, 'complete']);
    Route::post('/goals/{goal}/replan', [GoalController::class, 'replan']);

    // Route::put('/goals/{goal}/availability', [GoalController::class, 'updateAvailability']);
    // Route::put('/goals/{goal}/deadline', [GoalController::class, 'updateDeadline']);

    // Tasks (nested under goals) Not usable yet
    Route::get('/goals/{goal}/tasks', [TaskController::class, 'index']);

    // Tasks (direct access)
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/tasks/{task}', [TaskController::class, 'update']);
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
    Route::post('/tasks/{task}/skip', [TaskController::class, 'skip']);
    Route::post('/tasks/{task}/postpone', [TaskController::class, 'postpone']);
    Route::post('/tasks/{task}/recover', [TaskController::class, 'recover']);

    // Calendar Views
    Route::get('/calendar', [CalendarController::class, 'index']);
    Route::get('/today', [CalendarController::class, 'today']);
    Route::get('/next', [CalendarController::class, 'next']);

    // Statistics
    Route::get('/stats/overview', [CalendarController::class, 'statsOverview']);

    // FCM Notifications
    Route::post('/user/fcm-token', [NotificationController::class, 'storeToken']);
    Route::delete('/user/fcm-token', [NotificationController::class, 'removeToken']);
    Route::post('/test-notification', [NotificationController::class, 'sendTest']);
    Route::post('/test-notification-with-actions', [NotificationController::class, 'sendTestWithActions']);
    Route::get('/notification-status', [NotificationController::class, 'status']);
});
