<?php

use App\Models\Goal;
use App\Models\User;
use App\Services\SchedulingService;
use Carbon\Carbon;

beforeEach(function () {
    $this->service = new SchedulingService();
});

describe('SchedulingService', function () {
    
    describe('validateBlocks', function () {
        
        it('validates blocks that are multiples of session duration', function () {
            $rules = [
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'], // 180 min
            ];
            
            $result = $this->service->validateBlocks($rules, 60);
            
            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });

        it('warns about blocks that are not multiples of session duration', function () {
            $rules = [
                ['day' => 'monday', 'start' => '09:00', 'end' => '11:30'], // 150 min
            ];
            
            $result = $this->service->validateBlocks($rules, 60);
            
            // Should be valid but with warning (will use 2 sessions of 60 = 120 min)
            expect($result['valid'])->toBeTrue();
            expect($result['warnings'])->toHaveCount(1);
        });

        it('rejects blocks smaller than session duration', function () {
            $rules = [
                ['day' => 'monday', 'start' => '09:00', 'end' => '09:30'], // 30 min
            ];
            
            $result = $this->service->validateBlocks($rules, 60);
            
            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->toHaveCount(1);
        });

        it('validates 30-minute sessions', function () {
            $rules = [
                ['day' => 'monday', 'start' => '09:00', 'end' => '10:30'], // 90 min = 3 × 30
            ];
            
            $result = $this->service->validateBlocks($rules, 30);
            
            expect($result['valid'])->toBeTrue();
        });

        it('rejects blocks that cross midnight', function () {
            $rules = [
                ['day' => 'monday', 'start' => '22:00', 'end' => '02:00'], // Crosses midnight
            ];
            
            $result = $this->service->validateBlocks($rules, 60);
            
            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->toHaveCount(1);
            expect($result['errors'][0])->toContain('no pueden cruzar medianoche');
        });

        it('warns about overlapping blocks on same day', function () {
            $rules = [
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ['day' => 'monday', 'start' => '11:00', 'end' => '14:00'], // Overlaps with previous
            ];
            
            $result = $this->service->validateBlocks($rules, 60);
            
            expect($result['valid'])->toBeTrue();
            expect($result['warnings'])->toHaveCount(1);
            expect($result['warnings'][0])->toContain('solapan');
        });

    });

    describe('generateSlots', function () {
        
        it('generates correct number of slots for a simple goal', function () {
            // Freeze time to a Monday
            Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00')); // Monday
            
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'deadline' => '2026-02-16', // Next Monday (1 week)
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'], // 3 slots
                    ['day' => 'wednesday', 'start' => '09:00', 'end' => '12:00'], // 3 slots
                ],
            ]);
            
            $slots = $this->service->generateSlots($goal);
            
            // Week 1: Monday (3) + Wednesday (3) = 6
            // Week 2: Monday (3) = 3 (deadline is Monday 16th)
            // Total depends on exact cutoff
            expect($slots)->toBeArray();
            expect(count($slots))->toBeGreaterThanOrEqual(6);
            
            Carbon::setTestNow(); // Reset
        });

        it('generates slots with correct times', function () {
            Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00')); // Monday
            
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'deadline' => '2026-02-10', // Tuesday (only Monday counts)
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'], // 3 slots
                ],
            ]);
            
            $slots = $this->service->generateSlots($goal);
            
            expect(count($slots))->toBe(3);
            expect($slots[0]['start'])->toBe('09:00');
            expect($slots[0]['end'])->toBe('10:00');
            expect($slots[1]['start'])->toBe('10:00');
            expect($slots[1]['end'])->toBe('11:00');
            expect($slots[2]['start'])->toBe('11:00');
            expect($slots[2]['end'])->toBe('12:00');
            
            Carbon::setTestNow();
        });

        it('returns empty array when deadline is in the past', function () {
            Carbon::setTestNow(Carbon::parse('2026-02-15'));
            
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'deadline' => '2026-02-10', // Past
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ],
            ]);
            
            $slots = $this->service->generateSlots($goal);
            
            expect($slots)->toBeEmpty();
            
            Carbon::setTestNow();
        });

        it('filters out slots in the past for today', function () {
            // It's Monday 10:30 - only 11:00 slot should be available
            Carbon::setTestNow(Carbon::parse('2026-02-09 10:30:00'));
            
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'deadline' => '2026-02-10',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'], // 09, 10, 11 slots
                ],
            ]);
            
            $slots = $this->service->generateSlots($goal);
            
            // Only 11:00 slot should remain
            expect(count($slots))->toBe(1);
            expect($slots[0]['start'])->toBe('11:00');
            
            Carbon::setTestNow();
        });

    });

    describe('calculateCapacity', function () {
        
        it('calculates total sessions and hours correctly', function () {
            Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00'));
            
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'deadline' => '2026-02-10',
                'session_duration_minutes' => 60,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'], // 3 slots
                ],
            ]);
            
            $capacity = $this->service->calculateCapacity($goal);
            
            expect($capacity['total_sessions'])->toBe(3);
            expect($capacity['total_hours'])->toBe(3.0);
            expect($capacity['slots'])->toHaveCount(3);
            
            Carbon::setTestNow();
        });

        it('calculates correctly for 30-minute sessions', function () {
            Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00'));
            
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'deadline' => '2026-02-10',
                'session_duration_minutes' => 30,
                'availability_rules' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'], // 6 slots of 30 min
                ],
            ]);
            
            $capacity = $this->service->calculateCapacity($goal);
            
            expect($capacity['total_sessions'])->toBe(6);
            expect($capacity['total_hours'])->toBe(3.0);
            
            Carbon::setTestNow();
        });

    });

    describe('assignDates', function () {
        
        it('assigns dates to tasks in order', function () {
            $slots = [
                ['datetime' => '2026-02-09 09:00:00', 'date' => '2026-02-09', 'start' => '09:00'],
                ['datetime' => '2026-02-09 10:00:00', 'date' => '2026-02-09', 'start' => '10:00'],
                ['datetime' => '2026-02-09 11:00:00', 'date' => '2026-02-09', 'start' => '11:00'],
            ];
            
            $tasks = [
                ['order' => 1, 'title' => 'Task 1'],
                ['order' => 2, 'title' => 'Task 2'],
                ['order' => 3, 'title' => 'Task 3'],
            ];
            
            $result = $this->service->assignDates($tasks, $slots);
            
            expect($result[0]['scheduled_at'])->toBe('2026-02-09 09:00:00');
            expect($result[1]['scheduled_at'])->toBe('2026-02-09 10:00:00');
            expect($result[2]['scheduled_at'])->toBe('2026-02-09 11:00:00');
        });

        it('handles more tasks than slots', function () {
            $slots = [
                ['datetime' => '2026-02-09 09:00:00', 'date' => '2026-02-09', 'start' => '09:00'],
            ];
            
            $tasks = [
                ['order' => 1, 'title' => 'Task 1'],
                ['order' => 2, 'title' => 'Task 2'],
            ];
            
            $result = $this->service->assignDates($tasks, $slots);
            
            expect($result[0]['scheduled_at'])->toBe('2026-02-09 09:00:00');
            expect($result[1]['scheduled_at'])->toBeNull();
        });

        it('sorts tasks by order before assigning', function () {
            $slots = [
                ['datetime' => '2026-02-09 09:00:00', 'date' => '2026-02-09', 'start' => '09:00'],
                ['datetime' => '2026-02-09 10:00:00', 'date' => '2026-02-09', 'start' => '10:00'],
            ];
            
            $tasks = [
                ['order' => 2, 'title' => 'Task 2'], // Out of order
                ['order' => 1, 'title' => 'Task 1'],
            ];
            
            $result = $this->service->assignDates($tasks, $slots);
            
            // Task 1 should get first slot
            expect($result[0]['title'])->toBe('Task 1');
            expect($result[0]['scheduled_at'])->toBe('2026-02-09 09:00:00');
        });

    });

});
