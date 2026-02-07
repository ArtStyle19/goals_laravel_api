<?php

use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

describe('Goals', function () {

    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    describe('GET /api/goals', function () {
        
        it('lists user goals', function () {
            Goal::factory()->count(3)->create(['user_id' => $this->user->id]);
            Goal::factory()->create(); // Another user's goal

            $response = $this->actingAs($this->user)
                ->getJson('/api/goals');

            $response->assertStatus(200)
                ->assertJsonCount(3, 'goals');
        });

        it('requires authentication', function () {
            $response = $this->getJson('/api/goals');

            $response->assertStatus(401);
        });

    });

    describe('POST /api/goals', function () {
        
        it('creates a goal with valid data', function () {
            Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00'));

            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Aprender React',
                    'deadline' => '2026-03-01',
                    'session_duration_minutes' => 60,
                    'availability_rules' => [
                        ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                        ['day' => 'wednesday', 'start' => '14:00', 'end' => '17:00'],
                    ],
                ]);

            $response->assertStatus(201)
                ->assertJsonStructure([
                    'message',
                    'goal' => [
                        'id',
                        'title',
                        'deadline',
                        'session_duration_minutes',
                        'availability_rules',
                        'status',
                        'total_sessions',
                        'total_hours',
                    ],
                    'capacity' => [
                        'total_sessions',
                        'total_hours',
                    ],
                ]);

            $this->assertDatabaseHas('goals', [
                'title' => 'Aprender React',
                'user_id' => $this->user->id,
                'status' => 'draft',
            ]);

            Carbon::setTestNow();
        });

        it('validates session duration', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Test Goal',
                    'deadline' => '2026-03-01',
                    'session_duration_minutes' => 55, // Invalid
                    'availability_rules' => [
                        ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                    ],
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['session_duration_minutes']);
        });

        it('validates availability rules format', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Test Goal',
                    'deadline' => '2026-03-01',
                    'session_duration_minutes' => 60,
                    'availability_rules' => [
                        ['day' => 'invalid-day', 'start' => '09:00', 'end' => '12:00'],
                    ],
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['availability_rules.0.day']);
        });

        it('accepts blocks not exactly multiples but warns', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Test Goal',
                    'deadline' => '2026-03-01',
                    'session_duration_minutes' => 60,
                    'availability_rules' => [
                        ['day' => 'monday', 'start' => '09:00', 'end' => '11:30'], // 150 min, not multiple of 60
                    ],
                ]);

            // Should succeed but with warnings
            $response->assertStatus(201)
                ->assertJsonStructure(['warnings']);
        });

        it('rejects blocks smaller than session duration', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Test Goal',
                    'deadline' => '2026-03-01',
                    'session_duration_minutes' => 60,
                    'availability_rules' => [
                        ['day' => 'monday', 'start' => '09:00', 'end' => '09:30'], // 30 min, smaller than 60
                    ],
                ]);

            $response->assertStatus(422);
        });

        it('requires deadline in the future', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Test Goal',
                    'deadline' => '2020-01-01',
                    'session_duration_minutes' => 60,
                    'availability_rules' => [
                        ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                    ],
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['deadline']);
        });

    });

    describe('GET /api/goals/{goal}', function () {
        
        it('shows goal with tasks', function () {
            $goal = Goal::factory()->create(['user_id' => $this->user->id]);
            Task::factory()->count(3)->create(['goal_id' => $goal->id]);

            $response = $this->actingAs($this->user)
                ->getJson("/api/goals/{$goal->id}");

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'goal' => [
                        'id',
                        'title',
                        'tasks',
                    ],
                ])
                ->assertJsonCount(3, 'goal.tasks');
        });

        it('returns 403 for other user goal', function () {
            $otherGoal = Goal::factory()->create();

            $response = $this->actingAs($this->user)
                ->getJson("/api/goals/{$otherGoal->id}");

            $response->assertStatus(403);
        });

    });

    describe('PUT /api/goals/{goal}', function () {
        
        it('updates goal title', function () {
            $goal = Goal::factory()->create(['user_id' => $this->user->id]);

            $response = $this->actingAs($this->user)
                ->putJson("/api/goals/{$goal->id}", [
                    'title' => 'Updated Title',
                ]);

            $response->assertStatus(200)
                ->assertJson([
                    'goal' => [
                        'title' => 'Updated Title',
                    ],
                ]);
        });

    });

    describe('DELETE /api/goals/{goal}', function () {
        
        it('deletes goal', function () {
            $goal = Goal::factory()->create(['user_id' => $this->user->id]);

            $response = $this->actingAs($this->user)
                ->deleteJson("/api/goals/{$goal->id}");

            $response->assertStatus(200);
            $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
        });

    });

    describe('POST /api/goals/{goal}/tasks', function () {
        
        it('adds tasks to draft goal', function () {
            Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00')); // Monday

            $goal = Goal::factory()->create([
                'user_id' => $this->user->id,
                'status' => 'draft',
                'deadline' => '2026-03-01',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ],
            ]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/goals/{$goal->id}/tasks", [
                    'tasks' => [
                        ['title' => 'Task 1', 'description' => 'First task'],
                        ['title' => 'Task 2', 'description' => 'Second task'],
                    ],
                ]);

            $response->assertStatus(201)
                ->assertJsonCount(2, 'goal.tasks');

            // Goal should be activated
            $this->assertDatabaseHas('goals', [
                'id' => $goal->id,
                'status' => 'active',
            ]);

            // Tasks should have scheduled_at assigned
            $tasks = Task::where('goal_id', $goal->id)->get();
            expect($tasks)->toHaveCount(2);
            expect($tasks[0]->scheduled_at)->not->toBeNull();

            Carbon::setTestNow();
        });

        it('rejects adding tasks to active goal', function () {
            $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/goals/{$goal->id}/tasks", [
                    'tasks' => [
                        ['title' => 'Task 1'],
                    ],
                ]);

            $response->assertStatus(422);
        });

        it('rejects when not enough slots', function () {
            Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00'));

            $goal = Goal::factory()->create([
                'user_id' => $this->user->id,
                'status' => 'draft',
                'deadline' => '2026-02-10', // Only 1 day
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '10:00'], // Only 1 slot
                ],
            ]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/goals/{$goal->id}/tasks", [
                    'tasks' => [
                        ['title' => 'Task 1'],
                        ['title' => 'Task 2'],
                        ['title' => 'Task 3'],
                    ],
                ]);

            $response->assertStatus(422)
                ->assertJson([
                    'message' => 'No hay suficientes slots disponibles',
                ]);

            Carbon::setTestNow();
        });

    });

    describe('GET /api/goals/{goal}/stats', function () {
        
        it('returns goal statistics', function () {
            $goal = Goal::factory()->active()->create([
                'user_id' => $this->user->id,
                'session_duration_minutes' => 60,
                'total_hours' => 5,
            ]);
            
            Task::factory()->count(2)->completed()->create(['goal_id' => $goal->id]);
            Task::factory()->count(1)->skipped()->create(['goal_id' => $goal->id]);
            Task::factory()->count(2)->create(['goal_id' => $goal->id]); // scheduled

            $response = $this->actingAs($this->user)
                ->getJson("/api/goals/{$goal->id}/stats");

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'stats' => [
                        'total_tasks',
                        'completed_tasks',
                        'skipped_tasks',
                        'pending_tasks',
                        'progress_percentage',
                        'hours_completed',
                        'hours_remaining',
                        'days_until_deadline',
                        'on_track',
                    ],
                ])
                ->assertJson([
                    'stats' => [
                        'total_tasks' => 5,
                        'completed_tasks' => 2,
                        'skipped_tasks' => 1,
                        'pending_tasks' => 2,
                        'progress_percentage' => 40.0,
                    ],
                ]);
        });

    });

    describe('POST /api/goals/{goal}/complete', function () {
        
        it('completes goal', function () {
            $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/goals/{$goal->id}/complete");

            $response->assertStatus(200);

            $this->assertDatabaseHas('goals', [
                'id' => $goal->id,
                'status' => 'completed',
            ]);
        });

    });

});
