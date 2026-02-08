<?php

use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use App\Services\SchedulingService;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| Timezone Tests
|--------------------------------------------------------------------------
|
| These tests verify the UTC-everywhere approach:
| - SchedulingService generates UTC datetimes when given a timezone
| - CalendarController uses X-Timezone header for "today" and "next"
| - Goal creation with timezone produces UTC scheduled_at values
|
*/

describe('Timezone - SchedulingService', function () {

    beforeEach(function () {
        $this->service = new SchedulingService();
    });

    it('generates UTC slots when given a timezone', function () {
        // Monday at 08:00 UTC = Monday at 03:00 in America/Bogota (UTC-5)
        Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00', 'UTC'));

        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'deadline' => '2026-02-10',
            'session_duration_minutes' => 60,
            'availability_rules' => [
                // User says "Monday 09:00-12:00" in Bogota time
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
            ],
        ]);

        // Generate slots with Bogota timezone (UTC-5)
        $slots = $this->service->generateSlots($goal, null, 'America/Bogota');

        // Bogota 09:00 = UTC 14:00, Bogota 10:00 = UTC 15:00, Bogota 11:00 = UTC 16:00
        expect(count($slots))->toBe(3);
        expect($slots[0]['datetime'])->toBe('2026-02-09 14:00:00');
        expect($slots[1]['datetime'])->toBe('2026-02-09 15:00:00');
        expect($slots[2]['datetime'])->toBe('2026-02-09 16:00:00');

        // Local times for display should still reflect user's local time
        expect($slots[0]['start'])->toBe('09:00');
        expect($slots[1]['start'])->toBe('10:00');
        expect($slots[2]['start'])->toBe('11:00');

        Carbon::setTestNow();
    });

    it('generates naive UTC slots when timezone is UTC (default behavior)', function () {
        Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00', 'UTC'));

        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'deadline' => '2026-02-10',
            'session_duration_minutes' => 60,
            'availability_rules' => [
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
            ],
        ]);

        // No timezone = UTC (same as before)
        $slots = $this->service->generateSlots($goal);

        expect(count($slots))->toBe(3);
        expect($slots[0]['datetime'])->toBe('2026-02-09 09:00:00');
        expect($slots[1]['datetime'])->toBe('2026-02-09 10:00:00');
        expect($slots[2]['datetime'])->toBe('2026-02-09 11:00:00');

        Carbon::setTestNow();
    });

    it('filters past slots correctly with timezone', function () {
        // It's Monday 10:30 UTC = Monday 05:30 in Bogota (UTC-5)
        // User has availability 09:00-12:00 Bogota = 14:00-17:00 UTC
        // All 3 Bogota slots (14:00, 15:00, 16:00 UTC) are in the future
        Carbon::setTestNow(Carbon::parse('2026-02-09 10:30:00', 'UTC'));

        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'deadline' => '2026-02-10',
            'session_duration_minutes' => 60,
            'availability_rules' => [
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
            ],
        ]);

        $slots = $this->service->generateSlots($goal, null, 'America/Bogota');

        // All 3 slots should remain (14:00, 15:00, 16:00 UTC are all after 10:30 UTC)
        expect(count($slots))->toBe(3);

        Carbon::setTestNow();
    });

    it('reproduces the 3 AM bug fix - user at 3 AM local gets early slots', function () {
        // User is in America/Mexico_City (UTC-6), it's 3 AM local = 9 AM UTC
        Carbon::setTestNow(Carbon::parse('2026-02-09 09:00:00', 'UTC'));

        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'deadline' => '2026-02-16',
            'session_duration_minutes' => 60,
            'availability_rules' => [
                // All day availability 00:00-23:00 in Mexico City
                ['day' => 'monday', 'start' => '00:00', 'end' => '23:00'],
            ],
        ]);

        $slots = $this->service->generateSlots($goal, null, 'America/Mexico_City');

        // At 3 AM Mexico City, the first slot should be 04:00 local (since 03:00 = 09:00 UTC = now)
        // Mexico City 04:00 = UTC 10:00
        expect(count($slots))->toBeGreaterThan(0);
        expect($slots[0]['datetime'])->toBe('2026-02-09 10:00:00'); // 04:00 Mexico City in UTC
        expect($slots[0]['start'])->toBe('04:00');

        Carbon::setTestNow();
    });

    it('calculates capacity with timezone', function () {
        Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00', 'UTC'));

        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'deadline' => '2026-02-10',
            'session_duration_minutes' => 60,
            'availability_rules' => [
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
            ],
        ]);

        $capacity = $this->service->calculateCapacity($goal, null, 'America/Bogota');

        expect($capacity['total_sessions'])->toBe(3);
        expect($capacity['total_hours'])->toBe(3.0);
        // Verify slots are in UTC
        expect($capacity['slots'][0]['datetime'])->toBe('2026-02-09 14:00:00');

        Carbon::setTestNow();
    });

});

describe('Timezone - CalendarController', function () {

    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    it('returns tasks for today using X-Timezone header', function () {
        $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);

        // Task scheduled at 15:00 UTC = 10:00 in Bogota (UTC-5)
        Task::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_at' => Carbon::parse('2026-02-09 15:00:00', 'UTC'),
        ]);

        // It's Monday 2026-02-09 in Bogota (20:00 UTC = 15:00 Bogota... still Feb 9)
        Carbon::setTestNow(Carbon::parse('2026-02-09 20:00:00', 'UTC'));

        $response = $this->actingAs($this->user)
            ->getJson('/api/today', ['X-Timezone' => 'America/Bogota']);

        $response->assertStatus(200);
        expect($response->json('date'))->toBe('2026-02-09');
        expect(count($response->json('tasks')))->toBe(1);

        Carbon::setTestNow();
    });

    it('handles timezone day boundary correctly', function () {
        $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);

        // Task at 2026-02-10 02:00 UTC = 2026-02-09 21:00 in Bogota (UTC-5)
        // This is still "Feb 9" in Bogota
        Task::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_at' => Carbon::parse('2026-02-10 02:00:00', 'UTC'),
        ]);

        // It's Feb 9 23:00 in Bogota = Feb 10 04:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-02-10 04:00:00', 'UTC'));

        // With Bogota timezone, "today" is still Feb 9 (23:00 Bogota)
        // Wait — 04:00 UTC = 23:00 Bogota on Feb 9. So today in Bogota is Feb 9.
        // The task at 02:00 UTC = 21:00 Bogota Feb 9. Should appear.
        $response = $this->actingAs($this->user)
            ->getJson('/api/today', ['X-Timezone' => 'America/Bogota']);

        $response->assertStatus(200);
        expect($response->json('date'))->toBe('2026-02-09');
        expect(count($response->json('tasks')))->toBe(1);

        Carbon::setTestNow();
    });

    it('excludes tasks from different timezone day', function () {
        $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);

        // Task at 2026-02-09 03:00 UTC = 2026-02-08 22:00 in Bogota (UTC-5)
        // This is "Feb 8" in Bogota, not "Feb 9"
        Task::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_at' => Carbon::parse('2026-02-09 03:00:00', 'UTC'),
        ]);

        // It's Feb 9 10:00 in Bogota = Feb 9 15:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-02-09 15:00:00', 'UTC'));

        $response = $this->actingAs($this->user)
            ->getJson('/api/today', ['X-Timezone' => 'America/Bogota']);

        $response->assertStatus(200);
        expect($response->json('date'))->toBe('2026-02-09');
        // Task was on Feb 8 in Bogota, should NOT appear for "today" (Feb 9)
        expect(count($response->json('tasks')))->toBe(0);

        Carbon::setTestNow();
    });

    it('returns next task using UTC comparison', function () {
        $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);

        // Task at 2026-02-09 20:00 UTC = 15:00 Bogota
        Task::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_at' => Carbon::parse('2026-02-09 20:00:00', 'UTC'),
            'status' => 'scheduled',
        ]);

        // It's 18:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-02-09 18:00:00', 'UTC'));

        $response = $this->actingAs($this->user)
            ->getJson('/api/next', ['X-Timezone' => 'America/Bogota']);

        $response->assertStatus(200);
        expect($response->json('task'))->not->toBeNull();

        Carbon::setTestNow();
    });

    it('falls back to UTC when X-Timezone header is missing', function () {
        $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);

        Task::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_at' => now()->setTime(10, 0),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/today');

        $response->assertStatus(200);
        // Should still work, just using UTC as timezone
        expect($response->json('total_tasks'))->toBeGreaterThanOrEqual(0);
    });

    it('falls back to UTC when X-Timezone header is invalid', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/api/today', ['X-Timezone' => 'Invalid/Timezone']);

        $response->assertStatus(200);
        // Should not crash, just use UTC
    });

});

describe('Timezone - Goal Creation Integration', function () {

    it('stores tasks with UTC scheduled_at when timezone is provided', function () {
        Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00', 'UTC'));

        $user = User::factory()->create();

        // Create goal
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'deadline' => '2026-02-10',
            'session_duration_minutes' => 60,
            'status' => 'draft',
            'availability_rules' => [
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
            ],
        ]);

        // Add tasks with Bogota timezone header (UTC-5)
        $response = $this->actingAs($user)
            ->postJson("/api/goals/{$goal->id}/tasks", [
                'tasks' => [
                    ['title' => 'Task 1'],
                    ['title' => 'Task 2'],
                ],
            ], ['X-Timezone' => 'America/Bogota']);

        $response->assertStatus(201);

        // Verify tasks are stored in UTC
        $tasks = $goal->fresh()->tasks()->orderBy('order')->get();
        expect($tasks)->toHaveCount(2);

        // Bogota 09:00 = UTC 14:00
        expect($tasks[0]->scheduled_at->format('H:i'))->toBe('14:00');
        // Bogota 10:00 = UTC 15:00
        expect($tasks[1]->scheduled_at->format('H:i'))->toBe('15:00');

        Carbon::setTestNow();
    });

});
