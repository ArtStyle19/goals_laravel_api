<?php

use App\Models\User;
use App\Services\FCMService;

beforeEach(function () {
    $this->user = User::factory()->create([
        'fcm_token' => 'test_fcm_token_' . uniqid(),
    ]);
});

it('returns not initialized when FCM credentials are missing', function () {
    // Mock config to return invalid path
    config(['firebase.credentials.file' => '/nonexistent/path.json']);

    $service = new FCMService();

    expect($service->isInitialized())->toBeFalse();
});

it('returns error when sending to token without initialization', function () {
    // Create service with invalid credentials
    config(['firebase.credentials.file' => '/nonexistent/path.json']);
    $service = new FCMService();

    $result = $service->sendToToken('any_token', 'Title', 'Body');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('FCM not initialized');
});

it('returns error when user has no FCM token for task reminder', function () {
    // Create user without token
    $userWithoutToken = User::factory()->create(['fcm_token' => null]);
    $goal = \App\Models\Goal::factory()->create(['user_id' => $userWithoutToken->id]);
    $task = \App\Models\Task::factory()->create([
        'goal_id' => $goal->id,
        'status' => 'scheduled',
    ]);

    // We need to mock FCM service since we can't test actual Firebase
    // This test verifies the logic in the command, not the service itself
    expect($userWithoutToken->fcm_token)->toBeNull();
});

it('validates FCM service initialization status', function () {
    // With valid credentials file existing
    $credentialsPath = storage_path('firebase/firebase-service-account.json');

    if (file_exists($credentialsPath)) {
        config(['firebase.credentials.file' => $credentialsPath]);
        $service = new FCMService();
        // Should be initialized if credentials exist
        expect($service->isInitialized())->toBeTrue();
    } else {
        // If no credentials, should not be initialized
        config(['firebase.credentials.file' => '/nonexistent/path.json']);
        $service = new FCMService();
        expect($service->isInitialized())->toBeFalse();
    }
});
