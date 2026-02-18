<?php

use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use App\Services\FCMService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Mock FCM service to avoid actual Firebase calls
    $this->mockFcmService = Mockery::mock(FCMService::class);
    $this->mockFcmService->shouldReceive('isInitialized')->andReturn(true);
    $this->app->instance(FCMService::class, $this->mockFcmService);
});

afterEach(function () {
    Mockery::close();
    Cache::flush();
});

it('sends reminder for task starting in 15 minutes', function () {
    // Arrange: Create user with FCM token
    $user = User::factory()->create([
        'fcm_token' => 'test_fcm_token_' . uniqid(),
    ]);

    // Create goal and task starting in exactly 15 minutes
    $goal = Goal::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'scheduled',
        'title' => 'Test Task Reminder',
    ]);

    // Mock FCM service to expect sendTaskReminder call
    $this->mockFcmService
        ->shouldReceive('sendTaskReminder')
        ->once()
        ->with(Mockery::on(fn($u) => $u->id === $user->id), Mockery::on(fn($t) => $t->id === $task->id))
        ->andReturn(['success' => true, 'message' => 'Notification sent']);

    // Act: Run the command
    $this->artisan('app:send-task-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Found 1 task(s) for reminder')
        ->expectsOutputToContain('Reminder sent for task ' . $task->id);

    // Assert: Verify cache was set to prevent duplicates
    expect(Cache::has("task_reminder_sent:{$task->id}"))->toBeTrue();
});

it('skips tasks without FCM token', function () {
    // Arrange: Create user WITHOUT FCM token
    $user = User::factory()->create([
        'fcm_token' => null,
    ]);

    $goal = Goal::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'scheduled',
    ]);

    // Act: Run the command
    $this->artisan('app:send-task-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Found 1 task(s) for reminder')
        ->expectsOutputToContain('No FCM token for user');

    // Assert: No FCM call should be made
    $this->mockFcmService->shouldNotHaveReceived('sendTaskReminder');
});

it('prevents duplicate reminders with cache', function () {
    // Arrange: Create user with FCM token
    $user = User::factory()->create([
        'fcm_token' => 'test_fcm_token_' . uniqid(),
    ]);

    $goal = Goal::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'scheduled',
    ]);

    // Pre-set cache to simulate already sent reminder
    Cache::put("task_reminder_sent:{$task->id}", true, now()->addHours(2));

    // Act: Run the command
    $this->artisan('app:send-task-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Reminder already sent');

    // Assert: No FCM call should be made for duplicate
    $this->mockFcmService->shouldNotHaveReceived('sendTaskReminder');
});

it('clears invalid FCM tokens', function () {
    // Arrange: Create user with invalid FCM token
    $user = User::factory()->create([
        'fcm_token' => 'invalid_token_' . uniqid(),
    ]);

    $goal = Goal::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'scheduled',
    ]);

    // Mock FCM to return invalid token error
    $this->mockFcmService
        ->shouldReceive('sendTaskReminder')
        ->once()
        ->andReturn([
            'success' => false,
            'message' => 'Invalid registration token',
            'invalid_token' => true,
        ]);

    // Act: Run the command
    $this->artisan('app:send-task-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Failed to send reminder')
        ->expectsOutputToContain('Cleared invalid FCM token');

    // Assert: User token should be cleared
    $user->refresh();
    expect($user->fcm_token)->toBeNull();
});

it('finds tasks within 1-minute window of 15 minutes', function () {
    // Arrange: Create user with FCM token
    $user = User::factory()->create([
        'fcm_token' => 'test_fcm_token_' . uniqid(),
    ]);

    $goal = Goal::factory()->create(['user_id' => $user->id]);

    // Task at 14 minutes (within window)
    $task14 = Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(14),
        'status' => 'scheduled',
    ]);

    // Task at 16 minutes (within window)
    $task16 = Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(16),
        'status' => 'scheduled',
    ]);

    // Task at 20 minutes (outside window)
    $task20 = Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(20),
        'status' => 'scheduled',
    ]);

    // Mock FCM to expect 2 calls (14 and 16 min, not 20)
    $this->mockFcmService
        ->shouldReceive('sendTaskReminder')
        ->twice()
        ->andReturn(['success' => true, 'message' => 'Sent']);

    // Act: Run the command
    $this->artisan('app:send-task-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Found 2 task(s) for reminder');
});

it('skips completed or skipped tasks', function () {
    // Arrange: Create user with FCM token
    $user = User::factory()->create([
        'fcm_token' => 'test_fcm_token_' . uniqid(),
    ]);

    $goal = Goal::factory()->create(['user_id' => $user->id]);

    // Completed task
    Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'completed',
    ]);

    // Skipped task
    Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'skipped',
    ]);

    // Scheduled task (should be found)
    $scheduledTask = Task::factory()->create([
        'goal_id' => $goal->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'scheduled',
    ]);

    // Mock FCM to expect only 1 call
    $this->mockFcmService
        ->shouldReceive('sendTaskReminder')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Sent']);

    // Act: Run the command
    $this->artisan('app:send-task-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Found 1 task(s) for reminder');
});

it('fails gracefully when FCM is not initialized', function () {
    // Arrange: Override mock to return not initialized
    $mockFcm = Mockery::mock(FCMService::class);
    $mockFcm->shouldReceive('isInitialized')->andReturn(false);
    $this->app->instance(FCMService::class, $mockFcm);

    // Act & Assert
    $this->artisan('app:send-task-reminders')
        ->assertFailed()
        ->expectsOutputToContain('FCM service not initialized');
});

it('handles multiple users with different tasks', function () {
    // Arrange: Create multiple users
    $user1 = User::factory()->create(['fcm_token' => 'token_1_' . uniqid()]);
    $user2 = User::factory()->create(['fcm_token' => 'token_2_' . uniqid()]);

    $goal1 = Goal::factory()->create(['user_id' => $user1->id]);
    $goal2 = Goal::factory()->create(['user_id' => $user2->id]);

    // Tasks for both users
    Task::factory()->create([
        'goal_id' => $goal1->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'scheduled',
    ]);

    Task::factory()->create([
        'goal_id' => $goal2->id,
        'scheduled_at' => Carbon::now()->addMinutes(15),
        'status' => 'scheduled',
    ]);

    // Mock FCM to expect 2 calls
    $this->mockFcmService
        ->shouldReceive('sendTaskReminder')
        ->twice()
        ->andReturn(['success' => true, 'message' => 'Sent']);

    // Act
    $this->artisan('app:send-task-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Found 2 task(s) for reminder')
        ->expectsOutputToContain('Completed: 2 sent, 0 failed');
});
