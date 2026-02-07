<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Display a listing of tasks for a goal.
     */
    public function index(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $tasks = $goal->tasks()
            ->orderBy('scheduled_at')
            ->get()
            ->map(function ($task) use ($goal) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'order' => $task->order,
                    'scheduled_at' => $task->scheduled_at,
                    'end_at' => $task->end_at,
                    'duration_minutes' => $goal->session_duration_minutes,
                    'status' => $task->status,
                    'is_locked' => $task->is_locked,
                    'notes' => $task->notes,
                    'completed_at' => $task->completed_at,
                ];
            });

        return response()->json([
            'tasks' => $tasks,
        ]);
    }

    /**
     * Display the specified task.
     */
    public function show(Request $request, Task $task): JsonResponse
    {
        // Ensure user owns this task's goal
        if ($task->goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json([
            'task' => [
                'id' => $task->id,
                'goal_id' => $task->goal_id,
                'goal_title' => $task->goal->title,
                'title' => $task->title,
                'description' => $task->description,
                'order' => $task->order,
                'scheduled_at' => $task->scheduled_at,
                'end_at' => $task->end_at,
                'duration_minutes' => $task->duration_minutes,
                'status' => $task->status,
                'is_locked' => $task->is_locked,
                'notes' => $task->notes,
                'completed_at' => $task->completed_at,
            ],
        ]);
    }

    /**
     * Update the specified task (only notes).
     */
    public function update(Request $request, Task $task): JsonResponse
    {
        // Ensure user owns this task's goal
        if ($task->goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $task->update($validated);

        return response()->json([
            'message' => 'Tarea actualizada',
            'task' => $task->fresh(),
        ]);
    }

    /**
     * Mark task as completed.
     */
    public function complete(Request $request, Task $task): JsonResponse
    {
        // Ensure user owns this task's goal
        if ($task->goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($task->isCompleted()) {
            return response()->json([
                'message' => 'La tarea ya está completada',
            ], 422);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $task->markAsCompleted($validated['notes'] ?? null);

        return response()->json([
            'message' => 'Tarea completada',
            'task' => $task->fresh(),
        ]);
    }

    /**
     * Mark task as skipped.
     */
    public function skip(Request $request, Task $task): JsonResponse
    {
        // Ensure user owns this task's goal
        if ($task->goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (!$task->isScheduled()) {
            return response()->json([
                'message' => 'Solo se pueden saltar tareas programadas',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $task->markAsSkipped($validated['reason'] ?? null);

        return response()->json([
            'message' => 'Tarea saltada',
            'task' => $task->fresh(),
        ]);
    }

    /**
     * Lock task to prevent rescheduling. (old eplan)
     */
    // public function lock(Request $request, Task $task): JsonResponse
    // {
    //     // Ensure user owns this task's goal
    //     if ($task->goal->user_id !== $request->user()->id) {
    //         return response()->json(['message' => 'No autorizado'], 403);
    //     }

    //     $task->lock();

    //     return response()->json([
    //         'message' => 'Tarea bloqueada',
    //         'task' => $task->fresh(),
    //     ]);
    // }

    // /**
    //  * Unlock task to allow rescheduling.
    //  */
    // public function unlock(Request $request, Task $task): JsonResponse
    // {
    //     // Ensure user owns this task's goal
    //     if ($task->goal->user_id !== $request->user()->id) {
    //         return response()->json(['message' => 'No autorizado'], 403);
    //     }

    //     $task->unlock();

    //     return response()->json([
    //         'message' => 'Tarea desbloqueada',
    //         'task' => $task->fresh(),
    //     ]);
    // }
}
