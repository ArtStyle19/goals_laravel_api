<?php

use App\Models\User;
use App\Services\FCMService;

beforeEach(function () {
    $this->validFcmToken = 'eWGnWjW5Qbe7PUG4JeI4IC:APA91bG3QAft9VSGkJNy6O_zEi-cmaWsgT3UZBk41FuV1-1CIIOU0xugo7dDaYzUbFrTOHN6xW55LUDhHSi3CmcWKS0ChrOjGO61IrvASB1L4rJFYA_k5iU' . uniqid();

    $this->user = User::factory()->create([
        'fcm_token' => $this->validFcmToken,
    ]);
});

it('sends test notification to user by ID', function () {
    // Mock FCM service
    $mockFcm = Mockery::mock(FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(true);
    $mockFcm->shouldReceive('sendToToken')
        ->once()
        ->with(
            $this->validFcmToken,
            '🧪 Notificación de Prueba',
            'Esta es una prueba de FCM',
            Mockery::any()
        )
        ->andReturn(['success' => true, 'message' => 'Sent']);
    $this->app->instance(FCMService::class, $mockFcm);

    $this->artisan('app:send-test-notification', [
        'user' => $this->user->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Notification sent successfully');
});

it('sends test notification to user by email', function () {
    // Mock FCM service
    $mockFcm = Mockery::mock(FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(true);
    $mockFcm->shouldReceive('sendToToken')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Sent']);
    $this->app->instance(FCMService::class, $mockFcm);

    $this->artisan('app:send-test-notification', [
        'user' => $this->user->email,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Notification sent successfully');
});

it('sends notification with custom title and body', function () {
    // Mock FCM service
    $mockFcm = Mockery::mock(FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(true);
    $mockFcm->shouldReceive('sendToToken')
        ->once()
        ->with(
            $this->validFcmToken,
            'Mi Título Personalizado',
            'Mi mensaje personalizado',
            Mockery::any()
        )
        ->andReturn(['success' => true, 'message' => 'Sent']);
    $this->app->instance(FCMService::class, $mockFcm);

    $this->artisan('app:send-test-notification', [
        'user' => $this->user->id,
        '--title' => 'Mi Título Personalizado',
        '--body' => 'Mi mensaje personalizado',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Mi Título Personalizado')
        ->expectsOutputToContain('Mi mensaje personalizado');
});

it('fails when FCM is not initialized', function () {
    // Mock FCM service as not initialized
    $mockFcm = Mockery::mock(FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(false);
    $this->app->instance(FCMService::class, $mockFcm);

    $this->artisan('app:send-test-notification', [
        'user' => $this->user->id,
    ])
        ->assertFailed()
        ->expectsOutputToContain('FCM service is not initialized');
});

it('fails when user not found', function () {
    $mockFcm = Mockery::mock(FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(true);
    $this->app->instance(FCMService::class, $mockFcm);

    $this->artisan('app:send-test-notification', [
        'user' => 99999, // Non-existent ID
    ])
        ->assertFailed()
        ->expectsOutputToContain('User not found');
});

it('fails when user has no FCM token', function () {
    // Create user without token
    $userNoToken = User::factory()->create(['fcm_token' => null]);

    $mockFcm = Mockery::mock(FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(true);
    $this->app->instance(FCMService::class, $mockFcm);

    $this->artisan('app:send-test-notification', [
        'user' => $userNoToken->id,
    ])
        ->assertFailed()
        ->expectsOutputToContain('User has no FCM token registered');
});

it('clears invalid token and reports error', function () {
    // Mock FCM to return invalid token
    $mockFcm = Mockery::mock(FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(true);
    $mockFcm->shouldReceive('sendToToken')
        ->once()
        ->andReturn([
            'success' => false,
            'message' => 'Invalid registration token',
            'invalid_token' => true,
        ]);
    $this->app->instance(FCMService::class, $mockFcm);

    $this->artisan('app:send-test-notification', [
        'user' => $this->user->id,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Failed to send notification')
        ->expectsOutputToContain('The FCM token is invalid and has been cleared');

    // Verify token was cleared
    $this->user->refresh();
    expect($this->user->fcm_token)->toBeNull();
});

afterEach(function () {
    Mockery::close();
});
