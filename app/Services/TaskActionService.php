<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * TaskActionService — Single entry point for all task state transitions.
 *
 * Centralizes business rules for: complete, skip, postpone, recover.
 * The controller validates ownership/permissions, this service validates
 * state transitions and orchestrates side effects (cascade, notifications).
 *
 * State machine:
 *   scheduled → completed   (complete)
 *   scheduled → skipped     (skip)
 *   scheduled → scheduled*  (postpone — new slot, cascade)
 *   skipped   → scheduled*  (recover  — new slot, cascade)
 *
 * @see ReschedulingPipeline  — handles slot reassignment and deadline extension.
 */
class TaskActionService
{
    /** Maximum times a single task can be postponed. */
    private const MAX_POSTPONES_PER_TASK = 3;

    public function __construct(
        private ReschedulingPipeline $pipeline,
        private TaskNotificationScheduler $notificationScheduler,
    ) {}

    // ─── Complete ─────────────────────────────────────────

    /**
     * Mark a task as completed.
     *
     * @param Task $task
     * @param string|null $notes Optional notes to record.
     * @return array{task: Task, message: string}
     *
     * @throws \InvalidArgumentException If the task is already completed.
     */
    public function complete(Task $task, ?string $notes = null): array
    {
        if ($task->isCompleted()) {
            throw new \InvalidArgumentException('La tarea ya está completada.');
        }

        $task->markAsCompleted($notes);
        $this->notificationScheduler->cancelTaskNotifications($task->id);

        Log::info('TaskActionService: task completed', ['task_id' => $task->id]);

        return [
            'task' => $task->fresh(),
            'message' => 'Tarea completada',
        ];
    }

    // ─── Skip ─────────────────────────────────────────────

    /**
     * Mark a task as skipped.
     *
     * @param Task $task
     * @param string|null $reason Optional reason.
     * @return array{task: Task, message: string}
     *
     * @throws \InvalidArgumentException If the task is not scheduled.
     */
    public function skip(Task $task, ?string $reason = null): array
    {
        if (!$task->isScheduled()) {
            throw new \InvalidArgumentException('Solo se pueden saltar tareas programadas.');
        }

        $task->markAsSkipped($reason);
        $this->notificationScheduler->cancelTaskNotifications($task->id);

        Log::info('TaskActionService: task skipped', ['task_id' => $task->id]);

        return [
            'task' => $task->fresh(),
            'message' => 'Tarea saltada',
        ];
    }

    // ─── Postpone ─────────────────────────────────────────

    /**
     * Postpone a scheduled task to the next available slot.
     *
     * All subsequent scheduled tasks (by order) are cascaded forward.
     * Deadline is auto-extended if no slots are available.
     *
     * @param Task $task
     * @param string $timezone IANA timezone identifier.
     * @param string|null $reason Optional reason.
     * @return array{task: Task, message: string, cascade: array}
     *
     * @throws \InvalidArgumentException If the task cannot be postponed.
     */
    public function postpone(Task $task, string $timezone, ?string $reason = null): array
    {
        if (!$task->isScheduled()) {
            throw new \InvalidArgumentException('Solo se pueden posponer tareas programadas.');
        }

        // Enforce postpone limit.
        $postponeCount = $this->getPostponeCount($task);
        if ($postponeCount >= self::MAX_POSTPONES_PER_TASK) {
            throw new \InvalidArgumentException(
                "Esta tarea ya fue pospuesta {$postponeCount} veces (máximo " . self::MAX_POSTPONES_PER_TASK . ').'
            );
        }

        $goal = $task->goal;

        // Save the task's CURRENT scheduled_at BEFORE any modifications.
        // This is the slot the task must skip — the pipeline will only assign
        // slots strictly AFTER this datetime, ensuring the task actually moves
        // forward regardless of whether it's past, present, or future.
        $originalScheduledAt = \Carbon\Carbon::parse($task->scheduled_at)
            ->format('Y-m-d H:i:s');

        // Collect all tasks that need new slots:
        // This task + all subsequent scheduled tasks (order > this task's order).
        $tasksToReschedule = $goal->tasks()
            ->where('status', Task::STATUS_SCHEDULED)
            ->where('order', '>=', $task->order)
            ->orderBy('order')
            ->get();

        // Record the postpone in notes (audit trail for LLM replan).
        $task->markAsPostponed($reason);

        // Run the cascade via pipeline.
        // Pass afterDatetime = originalScheduledAt so the pipeline only assigns
        // slots that are STRICTLY AFTER the task's current slot. This handles:
        //   - Past/overdue tasks: gets next available future slot (correct)
        //   - Current tasks (right now): skips current slot, gets next (correct)
        //   - Future tasks: skips current slot, gets the one after (correct)
        $cascadeResult = $this->pipeline->reschedule(
            $goal,
            $tasksToReschedule,
            $timezone,
            afterDatetime: $originalScheduledAt,
        );

        $this->notificationScheduler->cancelTaskNotifications($task->id);

        Log::info('TaskActionService: task postponed', [
            'task_id' => $task->id,
            'original_slot' => $originalScheduledAt,
            'cascade' => $cascadeResult,
        ]);

        return [
            'task' => $task->fresh(),
            'message' => 'Tarea pospuesta',
            'cascade' => $cascadeResult,
        ];
    }

    // ─── Recover ──────────────────────────────────────────

    /**
     * Recover a skipped task — set it back to scheduled with a new slot.
     *
     * Subsequent scheduled tasks are cascaded forward.
     *
     * @param Task $task
     * @param string $timezone IANA timezone identifier.
     * @return array{task: Task, message: string, cascade: array}
     *
     * @throws \InvalidArgumentException If the task cannot be recovered.
     */
    public function recover(Task $task, string $timezone): array
    {
        if (!$task->isSkipped()) {
            throw new \InvalidArgumentException('Solo se pueden recuperar tareas omitidas.');
        }

        $goal = $task->goal;

        if ($goal->isCompleted()) {
            throw new \InvalidArgumentException('No se puede recuperar una tarea de un objetivo completado.');
        }

        // Restore the task to scheduled status first.
        $task->markAsRecovered();

        // Collect: this task + all subsequent scheduled tasks.
        $tasksToReschedule = $goal->tasks()
            ->where('status', Task::STATUS_SCHEDULED)
            ->where('order', '>=', $task->order)
            ->orderBy('order')
            ->get();

        // Run the cascade via pipeline.
        $cascadeResult = $this->pipeline->reschedule($goal, $tasksToReschedule, $timezone);

        Log::info('TaskActionService: task recovered', [
            'task_id' => $task->id,
            'cascade' => $cascadeResult,
        ]);

        return [
            'task' => $task->fresh(),
            'message' => 'Tarea recuperada',
            'cascade' => $cascadeResult,
        ];
    }

    // ─── Helpers ──────────────────────────────────────────

    /**
     * Count how many times a task has been postponed (from notes audit trail).
     */
    private function getPostponeCount(Task $task): int
    {
        if (empty($task->notes)) {
            return 0;
        }

        return substr_count($task->notes, '[Pospuesta');
    }
}
