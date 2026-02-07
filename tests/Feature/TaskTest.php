<?php

use App\Models\Goal;
use App\Models\Task;
use App\Models\User;

describe('Tasks', function () {

    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->goal = Goal::factory()->active()->create(['user_id' => $this->user->id]);
    });

    describe('GET /api/goals/{goal}/tasks', function () {

        it('lists tasks for a goal', function () {
            Task::factory()->count(3)->create(['goal_id' => $this->goal->id]);

            $response = $this->actingAs($this->user)
                ->getJson("/api/goals/{$this->goal->id}/tasks");

            $response->assertStatus(200)
                ->assertJsonCount(3, 'tasks');
        });

        it('returns tasks ordered by scheduled_at', function () {
            Task::factory()->create([
                'goal_id' => $this->goal->id,
                'scheduled_at' => now()->addDays(2),
            ]);
            Task::factory()->create([
                'goal_id' => $this->goal->id,
                'scheduled_at' => now()->addDay(),
            ]);

            $response = $this->actingAs($this->user)
                ->getJson("/api/goals/{$this->goal->id}/tasks");

            $tasks = $response->json('tasks');
            expect($tasks[0]['scheduled_at'] < $tasks[1]['scheduled_at'])->toBeTrue();
        });

    });

    describe('GET /api/tasks/{task}', function () {

        it('shows task details', function () {
            $task = Task::factory()->create(['goal_id' => $this->goal->id]);

            $response = $this->actingAs($this->user)
                ->getJson("/api/tasks/{$task->id}");

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'task' => [
                        'id',
                        'goal_id',
                        'goal_title',
                        'title',
                        'description',
                        'scheduled_at',
                        'status',
                    ],
                ]);
        });

        it('returns 403 for other user task', function () {
            $otherGoal = Goal::factory()->create();
            $task = Task::factory()->create(['goal_id' => $otherGoal->id]);

            $response = $this->actingAs($this->user)
                ->getJson("/api/tasks/{$task->id}");

            $response->assertStatus(403);
        });

    });

    describe('PUT /api/tasks/{task}', function () {

        it('updates task notes', function () {
            $task = Task::factory()->create(['goal_id' => $this->goal->id]);

            $response = $this->actingAs($this->user)
                ->putJson("/api/tasks/{$task->id}", [
                    'notes' => 'My notes',
                ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('tasks', [
                'id' => $task->id,
                'notes' => 'My notes',
            ]);
        });

    });

    describe('POST /api/tasks/{task}/complete', function () {

        it('marks task as completed', function () {
            $task = Task::factory()->create(['goal_id' => $this->goal->id]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/tasks/{$task->id}/complete");

            $response->assertStatus(200);
            $this->assertDatabaseHas('tasks', [
                'id' => $task->id,
                'status' => 'completed',
            ]);
        });

        it('saves notes when completing', function () {
            $task = Task::factory()->create(['goal_id' => $this->goal->id]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/tasks/{$task->id}/complete", [
                    'notes' => 'Done!',
                ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('tasks', [
                'id' => $task->id,
                'notes' => 'Done!',
            ]);
        });

        it('rejects completing already completed task', function () {
            $task = Task::factory()->completed()->create(['goal_id' => $this->goal->id]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/tasks/{$task->id}/complete");

            $response->assertStatus(422);
        });

    });

    describe('POST /api/tasks/{task}/skip', function () {

        it('marks task as skipped', function () {
            $task = Task::factory()->create(['goal_id' => $this->goal->id]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/tasks/{$task->id}/skip", [
                    'reason' => 'No pude hoy',
                ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('tasks', [
                'id' => $task->id,
                'status' => 'skipped',
                'notes' => 'No pude hoy',
            ]);
        });

        it('rejects skipping completed task', function () {
            $task = Task::factory()->completed()->create(['goal_id' => $this->goal->id]);

            $response = $this->actingAs($this->user)
                ->postJson("/api/tasks/{$task->id}/skip");

            $response->assertStatus(422);
        });

    });

    ## Funcionalidades de lock/unlock comentadas debido a replan inneficiente
    // describe('POST /api/tasks/{task}/lock', function () {

    //     it('locks task', function () {
    //         $task = Task::factory()->create(['goal_id' => $this->goal->id]);

    //         $response = $this->actingAs($this->user)
    //             ->postJson("/api/tasks/{$task->id}/lock");

    //         $response->assertStatus(200);
    //         $this->assertDatabaseHas('tasks', [
    //             'id' => $task->id,
    //             'is_locked' => true,
    //         ]);
    //     });

    // });

    // describe('POST /api/tasks/{task}/unlock', function () {

    //     it('unlocks task', function () {
    //         $task = Task::factory()->locked()->create(['goal_id' => $this->goal->id]);

    //         $response = $this->actingAs($this->user)
    //             ->postJson("/api/tasks/{$task->id}/unlock");

    //         $response->assertStatus(200);
    //         $this->assertDatabaseHas('tasks', [
    //             'id' => $task->id,
    //             'is_locked' => false,
    //         ]);
    //     });

    // });

});
