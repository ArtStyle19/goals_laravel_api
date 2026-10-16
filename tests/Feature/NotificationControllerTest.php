<?php

use App\Models\User;

beforeEach(function () {
    // Create a valid FCM token (min 100 chars as per validation)
    $this->validFcmToken = 'eWGnWjW5Qbe7PUG4JeI4IC:APA91bG3QAft9VSGkJNy6O_zEi-cmaWsgT3UZBk41FuV1-1CIIOU0xugo7dDaYzUbFrTOHN6xW55LUDhHSi3CmcWKS0ChrOjGO61IrvASB1L4rJFYA_k5iU' . uniqid();

    $this->user = User::factory()->create([
        'fcm_token' => $this->validFcmToken,
    ]);
    $this->actingAs($this->user);
});

it('registers FCM token successfully', function () {
    $newToken = 'eWGnWjW5Qbe7PUG4JeI4IC:APA91bG3QAft9VSGkJNy6O_zEi-cmaWsgT3UZBk41FuV1-1CIIOU0xugo7dDaYzUbFrTOHN6xW55LUDhHSi3CmcWKS0ChrOjGO61IrvASB1L4rJFYA_k5iU' . uniqid();

    $response = $this->postJson('/api/user/fcm-token', [
        'fcm_token' => $newToken,
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'FCM token registered successfully']);

    $this->user->refresh();
    expect($this->user->fcm_token)->toBe($newToken);
});

it('validates FCM token is required', function () {
    $response = $this->postJson('/api/user/fcm-token', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['fcm_token']);
});

it('validates FCM token minimum length', function () {
    $response = $this->postJson('/api/user/fcm-token', [
        'fcm_token' => 'short_token',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['fcm_token']);
});

it('removes FCM token on logout', function () {
    $response = $this->deleteJson('/api/user/fcm-token');

    $response->assertOk()
        ->assertJson(['message' => 'FCM token removed successfully']);

    $this->user->refresh();
    expect($this->user->fcm_token)->toBeNull();
});

it('returns notification status', function () {
    $response = $this->getJson('/api/notification-status');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'fcm_initialized',
                'credentials_exist',
            ],
        ]);
});

it('returns 400 when user has no FCM token for test notification', function () {
    // User without token
    $userNoToken = User::factory()->create(['fcm_token' => null]);
    $this->actingAs($userNoToken);

    $response = $this->postJson('/api/test-notification', [
        'title' => 'Test',
        'body' => 'Test body',
    ]);

    $response->assertBadRequest()
        ->assertJson(['message' => 'No FCM token registered. Please register your device first.']);
});

it('handles invalid FCM token by clearing it', function () {
    // Mock FCM to return invalid token
    $mockFcm = Mockery::mock(\App\Services\FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(true);
    $mockFcm->shouldReceive('sendToToken')->andReturn([
        'success' => false,
        'message' => 'Invalid token',
        'invalid_token' => true,
    ]);
    $this->app->instance(\App\Services\FCMService::class, $mockFcm);

    $response = $this->postJson('/api/test-notification', [
        'title' => 'Test',
        'body' => 'Test body',
    ]);

    $response->assertBadRequest()
        ->assertJson(['message' => 'FCM token is invalid and has been removed. Please re-register.']);

    // Verify token was cleared
    $this->user->refresh();
    expect($this->user->fcm_token)->toBeNull();
});

it('handles FCM service errors gracefully', function () {
    // Mock FCM to return error
    $mockFcm = Mockery::mock(\App\Services\FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(true);
    $mockFcm->shouldReceive('sendToToken')->andReturn([
        'success' => false,
        'message' => 'Some FCM error',
    ]);
    $this->app->instance(\App\Services\FCMService::class, $mockFcm);

    $response = $this->postJson('/api/test-notification', [
        'title' => 'Test',
        'body' => 'Test body',
    ]);

    $response->assertServerError()
        ->assertJson(['message' => 'Some FCM error']);
});

afterEach(function () {
    Mockery::close();
});
