<?php

use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

describe('Calendar', function () {

    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    describe('GET /api/calendar', function () {
        
        it('returns tasks for date range', function () {
            $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);
            
            Task::factory()->create([
                'goal_id' => $goal->id,
                'scheduled_at' => now()->setTime(10, 0),
            ]);

            $from = now()->format('Y-m-d');
            $to = now()->addDays(6)->format('Y-m-d');

            $response = $this->actingAs($this->user)
                ->getJson("/api/calendar?from={$from}&to={$to}");

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'from',
                    'to',
                    'tasks',
                    'total',
                ]);
        });

        it('requires from and to parameters', function () {
            $response = $this->actingAs($this->user)
                ->getJson('/api/calendar');

            $response->assertStatus(422);
        });

        it('includes goal info with each task', function () {
            $goal = Goal::factory()->active()->create([
                'user_id' => $this->user->id,
                'title' => 'Mi Meta',
            ]);
            
            Task::factory()->create([
                'goal_id' => $goal->id,
                'scheduled_at' => now()->setTime(10, 0),
            ]);

            $from = now()->format('Y-m-d');
            $to = now()->format('Y-m-d');

            $response = $this->actingAs($this->user)
                ->getJson("/api/calendar?from={$from}&to={$to}");

            $tasks = $response->json('tasks');
            expect($tasks)->not->toBeEmpty();
            expect($tasks[0]['goal_title'])->toBe('Mi Meta');
        });

        it('only returns own tasks', function () {
            $otherUser = User::factory()->create();
            $otherGoal = Goal::factory()->active()->create(['user_id' => $otherUser->id]);
            
            Task::factory()->create([
                'goal_id' => $otherGoal->id,
                'scheduled_at' => now()->setTime(10, 0),
            ]);

            $from = now()->format('Y-m-d');
            $to = now()->format('Y-m-d');

            $response = $this->actingAs($this->user)
                ->getJson("/api/calendar?from={$from}&to={$to}");

            expect($response->json('total'))->toBe(0);
        });

    });

    describe('GET /api/today', function () {
        
        it('returns tasks for today', function () {
            $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);
            
            Task::factory()->create([
                'goal_id' => $goal->id,
                'scheduled_at' => now()->setTime(10, 0),
            ]);
            Task::factory()->create([
                'goal_id' => $goal->id,
                'scheduled_at' => now()->setTime(14, 0),
            ]);

            $response = $this->actingAs($this->user)
                ->getJson('/api/today');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'date',
                    'tasks',
                    'total_tasks',
                    'total_minutes',
                ]);
            expect(count($response->json('tasks')))->toBe(2);
        });

        it('returns empty when no tasks today', function () {
            $response = $this->actingAs($this->user)
                ->getJson('/api/today');

            $response->assertStatus(200);
            expect($response->json('total_tasks'))->toBe(0);
        });

    });

    describe('GET /api/next', function () {
        
        it('returns next upcoming task', function () {
            $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);
            
            Task::factory()->create([
                'goal_id' => $goal->id,
                'scheduled_at' => now()->addHour(),
                'status' => 'scheduled',
            ]);

            $response = $this->actingAs($this->user)
                ->getJson('/api/next');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'task',
                    'starts_in',
                ]);
            expect($response->json('task'))->not->toBeNull();
        });

        it('returns null when no upcoming tasks', function () {
            $response = $this->actingAs($this->user)
                ->getJson('/api/next');

            $response->assertStatus(200);
            expect($response->json('task'))->toBeNull();
        });

    });

});
