<?php

use App\Models\Goal;
use App\Models\User;
use App\Services\LLMService;
use App\Services\PlannerService;
use Carbon\Carbon;
use Mockery\MockInterface;

describe('Generate Endpoint', function () {

    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    describe('POST /api/goals/{goal}/generate', function () {
        
        it('generates tasks using LLM and assigns dates', function () {
            Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00')); // Monday

            $goal = Goal::factory()->create([
                'user_id' => $this->user->id,
                'title' => 'Aprender React',
                'status' => 'draft',
                'deadline' => '2026-03-01',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                    ['day' => 'wednesday', 'start' => '09:00', 'end' => '12:00'],
                ],
            ]);

            // Mock LLMService
            $this->mock(LLMService::class, function (MockInterface $mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('call')->andReturn(json_encode([
                    'tasks' => [
                        ['order' => 1, 'title' => 'Introducción a React', 'description' => 'Conceptos básicos'],
                        ['order' => 2, 'title' => 'Componentes', 'description' => 'Crear componentes'],
                        ['order' => 3, 'title' => 'Props y State', 'description' => 'Manejar datos'],
                    ],
                ]));
                $mock->shouldReceive('extractJson')->andReturnUsing(function ($response) {
                    return json_decode($response, true);
                });
                $mock->shouldReceive('validateTasks')->andReturnUsing(function ($tasks, $expected) {
                    return [
                        'valid' => true,
                        'tasks' => array_map(function ($task, $index) {
                            return [
                                'order' => $index + 1,
                                'title' => $task['title'],
                                'description' => $task['description'] ?? null,
                            ];
                        }, $tasks, array_keys($tasks)),
                        'error' => null,
                        'warning' => null,
                    ];
                });
            });

            $response = $this->actingAs($this->user)
                ->postJson("/api/goals/{$goal->id}/generate");

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Plan generado exitosamente',
                ])
                ->assertJsonStructure([
                    'success',
                    'message',
                    'goal',
                    'stats' => ['total_tasks', 'total_hours'],
                ]);

            // Goal should be activated
            $this->assertDatabaseHas('goals', [
                'id' => $goal->id,
                'status' => 'active',
            ]);

            // Tasks should be created with dates
            $tasks = $goal->fresh()->tasks;
            expect($tasks)->toHaveCount(3);
            expect($tasks[0]->scheduled_at)->not->toBeNull();

            Carbon::setTestNow();
        });

        it('rejects generating for active goal', function () {
            $goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/goals/{$goal->id}/generate");

            $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ]);
        });

        it('returns error when LLM is not configured', function () {
            $goal = Goal::factory()->create([
                'user_id' => $this->user->id,
                'status' => 'draft',
            ]);

            // Mock LLMService as not configured
            $this->mock(LLMService::class, function (MockInterface $mock) {
                $mock->shouldReceive('isConfigured')->andReturn(false);
            });

            $response = $this->actingAs($this->user)
                ->postJson("/api/goals/{$goal->id}/generate");

            $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonStructure([
                    'error',
                    'suggestion',
                    'manual_endpoint',
                ]);
        });

        it('returns 403 for other user goal', function () {
            $otherGoal = Goal::factory()->create(['status' => 'draft']);

            $response = $this->actingAs($this->user)
                ->postJson("/api/goals/{$otherGoal->id}/generate");

            $response->assertStatus(403);
        });

        it('requires authentication', function () {
            $goal = Goal::factory()->create(['status' => 'draft']);

            $response = $this->postJson("/api/goals/{$goal->id}/generate");

            $response->assertStatus(401);
        });

    });

});
