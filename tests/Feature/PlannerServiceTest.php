<?php

use App\Models\Goal;
use App\Models\User;
use App\Services\LLMService;
use App\Services\PlannerService;
use App\Services\SchedulingService;
use Carbon\Carbon;
use Mockery\MockInterface;

describe('PlannerService', function () {

    beforeEach(function () {
        Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00')); // Monday
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    describe('generatePlan', function () {
        
        it('generates plan successfully with mocked LLM', function () {
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'title' => 'Aprender React',
                'status' => 'draft',
                'deadline' => '2026-03-01',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ],
            ]);

            // Mock LLMService
            $mockLLM = $this->mock(LLMService::class, function (MockInterface $mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('call')->once()->andReturn(json_encode([
                    'tasks' => [
                        ['order' => 1, 'title' => 'Task 1', 'description' => 'Desc 1'],
                        ['order' => 2, 'title' => 'Task 2', 'description' => 'Desc 2'],
                        ['order' => 3, 'title' => 'Task 3', 'description' => 'Desc 3'],
                    ],
                ]));
                $mock->shouldReceive('extractJson')->andReturnUsing(function ($response) {
                    return json_decode($response, true);
                });
                $mock->shouldReceive('validateTasks')->andReturn([
                    'valid' => true,
                    'tasks' => [
                        ['order' => 1, 'title' => 'Task 1', 'description' => 'Desc 1'],
                        ['order' => 2, 'title' => 'Task 2', 'description' => 'Desc 2'],
                        ['order' => 3, 'title' => 'Task 3', 'description' => 'Desc 3'],
                    ],
                    'error' => null,
                    'warning' => null,
                ]);
            });

            $schedulingService = app(SchedulingService::class);
            $plannerService = new PlannerService($mockLLM, $schedulingService);

            $result = $plannerService->generatePlan($goal);

            expect($result['success'])->toBeTrue();
            expect($result['tasks'])->toHaveCount(3);
            expect($result['tasks'][0]['scheduled_at'])->not->toBeNull();
            expect($result['error'])->toBeNull();
        });

        it('returns error when LLM not configured', function () {
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'status' => 'draft',
            ]);

            $mockLLM = $this->mock(LLMService::class, function (MockInterface $mock) {
                $mock->shouldReceive('isConfigured')->andReturn(false);
            });

            $schedulingService = app(SchedulingService::class);
            $plannerService = new PlannerService($mockLLM, $schedulingService);

            $result = $plannerService->generatePlan($goal);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('configurado');
        });

        it('returns error when LLM call fails', function () {
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'status' => 'draft',
                'deadline' => '2026-03-01',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ],
            ]);

            $mockLLM = $this->mock(LLMService::class, function (MockInterface $mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('call')->andReturn(null); // LLM fails
            });

            $schedulingService = app(SchedulingService::class);
            $plannerService = new PlannerService($mockLLM, $schedulingService);

            $result = $plannerService->generatePlan($goal);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('comunicarse');
        });

        it('returns error when no slots available', function () {
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'status' => 'draft',
                'deadline' => '2026-02-08', // Past deadline
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ],
            ]);

            $mockLLM = $this->mock(LLMService::class, function (MockInterface $mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
            });

            $schedulingService = app(SchedulingService::class);
            $plannerService = new PlannerService($mockLLM, $schedulingService);

            $result = $plannerService->generatePlan($goal);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('slots');
        });

        it('returns error when validation fails', function () {
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'status' => 'draft',
                'deadline' => '2026-03-01',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ],
            ]);

            $mockLLM = $this->mock(LLMService::class, function (MockInterface $mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('call')->andReturn('{"tasks": []}');
                $mock->shouldReceive('extractJson')->andReturn(['tasks' => []]);
                $mock->shouldReceive('validateTasks')->andReturn([
                    'valid' => false,
                    'tasks' => [],
                    'error' => 'No se recibieron tareas',
                    'warning' => null,
                ]);
            });

            $schedulingService = app(SchedulingService::class);
            $plannerService = new PlannerService($mockLLM, $schedulingService);

            $result = $plannerService->generatePlan($goal);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->not->toBeNull();
        });

    });

});
