<?php

use App\Models\Goal;
use App\Models\User;
use App\Services\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Edge Case Scheduling', function () {

    beforeEach(function () {
        Carbon::setTestNow(Carbon::parse('2026-02-05 08:00:00')); // Thursday
        $this->user = User::factory()->create();
        $this->service = app(SchedulingService::class);
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    describe('Night shift schedules', function () {

        it('handles evening schedules (18:00-22:00)', function () {
            $goal = Goal::create([
                'user_id' => $this->user->id,
                'title' => 'Evening Study',
                'deadline' => '2026-02-15',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '18:00', 'end' => '22:00'],
                ],
                'status' => Goal::STATUS_DRAFT,
            ]);

            $capacity = $this->service->calculateCapacity($goal);

            expect($capacity['total_sessions'])->toBe(4); // 4 hours = 4 sessions
            expect($capacity['slots'][0]['start'])->toBe('18:00');
            expect($capacity['slots'][3]['end'])->toBe('22:00');
        });

        it('rejects late night schedules that cross midnight (22:00-00:00) via API', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Late Night Study',
                    'deadline' => '2026-02-15',
                    'session_duration_minutes' => 60,
                    'availability_rules' => [
                        ['day' => 'friday', 'start' => '22:00', 'end' => '00:00'],
                    ],
                ]);

            // Should fail validation: end time must be after start time
            $response->assertStatus(422);
        });

        it('rejects overnight schedules (23:00-02:00) via API', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Overnight Study',
                    'deadline' => '2026-02-15',
                    'session_duration_minutes' => 60,
                    'availability_rules' => [
                        ['day' => 'saturday', 'start' => '23:00', 'end' => '02:00'],
                    ],
                ]);

            // Should fail validation: no midnight crossing allowed
            $response->assertStatus(422);
        });

        it('accepts late night up to 23:59', function () {
            $goal = Goal::create([
                'user_id' => $this->user->id,
                'title' => 'Late Night Study',
                'deadline' => '2026-02-07', // Only includes 1 Friday
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'friday', 'start' => '21:00', 'end' => '23:00'],
                ],
                'status' => Goal::STATUS_DRAFT,
            ]);

            $capacity = $this->service->calculateCapacity($goal);

            expect($capacity['total_sessions'])->toBe(2); // 2 hours = 2 sessions on 1 Friday
        });

    });

    describe('Early morning schedules', function () {

        it('handles very early morning (05:00-08:00)', function () {
            $goal = Goal::create([
                'user_id' => $this->user->id,
                'title' => 'Early Morning Study',
                'deadline' => '2026-02-15',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '05:00', 'end' => '08:00'],
                ],
                'status' => Goal::STATUS_DRAFT,
            ]);

            $capacity = $this->service->calculateCapacity($goal);

            expect($capacity['total_sessions'])->toBe(3);
        });

    });

    describe('Multiple blocks same day', function () {

        it('handles morning + afternoon blocks', function () {
            $goal = Goal::create([
                'user_id' => $this->user->id,
                'title' => 'Split Day',
                'deadline' => '2026-02-15',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                    ['day' => 'monday', 'start' => '14:00', 'end' => '17:00'],
                ],
                'status' => Goal::STATUS_DRAFT,
            ]);

            $capacity = $this->service->calculateCapacity($goal);

            expect($capacity['total_sessions'])->toBe(6); // 3 + 3 per Monday
        });

        it('handles overlapping blocks on same day with warning via API', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/goals', [
                    'title' => 'Overlapping Blocks',
                    'deadline' => '2026-02-15',
                    'session_duration_minutes' => 60,
                    'availability_rules' => [
                        ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                        ['day' => 'monday', 'start' => '11:00', 'end' => '14:00'],
                    ],
                ]);

            // Should succeed but with warning about overlapping blocks
            $response->assertStatus(201)
                ->assertJsonStructure(['warnings']);

            $warnings = $response->json('warnings');
            expect($warnings)->not->toBeEmpty();
        });

    });

    describe('Unusual session durations', function () {

        it('handles 45-minute sessions correctly', function () {
            $goal = Goal::create([
                'user_id' => $this->user->id,
                'title' => '45min Sessions',
                'deadline' => '2026-02-15',
                'session_duration_minutes' => 45,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'], // 180 min
                ],
                'status' => Goal::STATUS_DRAFT,
            ]);

            $capacity = $this->service->calculateCapacity($goal);

            expect($capacity['total_sessions'])->toBe(4); // 180/45 = 4
        });

        it('handles 90-minute sessions correctly', function () {
            $goal = Goal::create([
                'user_id' => $this->user->id,
                'title' => '90min Sessions',
                'deadline' => '2026-02-15',
                'session_duration_minutes' => 90,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'], // 180 min
                ],
                'status' => Goal::STATUS_DRAFT,
            ]);

            $capacity = $this->service->calculateCapacity($goal);

            expect($capacity['total_sessions'])->toBe(2); // 180/90 = 2
        });

    });

});
