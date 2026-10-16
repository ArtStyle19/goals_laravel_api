<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ReschedulingPipeline — Reusable slot reassignment engine.
 *
 * Given a goal and a set of tasks that need new slots, this service
 * generates available slots, filters occupied ones, and reassigns dates.
 *
 * Key design decisions:
 * - Generic: does NOT decide WHICH tasks to move — the caller does.
 * - Reusable: same pipeline will be used by TaskActionService (manual)
 *   and LLMReplanService (automated) in the future.
 * - Deadline is soft: auto-extends when no slots are available.
 * - Atomic: all changes within a DB transaction.
 *
 * @see TaskActionService  — the caller that decides what to reschedule.
 * @see SchedulingService  — generates raw slots from availability rules.
 */
class ReschedulingPipeline
{
    /** Default max days the deadline can be extended. */
    private const DEFAULT_MAX_EXTENSION_DAYS = 14;

    public function __construct(
        private SchedulingService $scheduling,
        private TaskNotificationScheduler $notificationScheduler,
    ) {}

    /**
     * Reschedule a collection of tasks into available slots.
     *
     * Tasks are assigned to slots in the order they appear in the collection
     * (caller must sort by `order` before calling).
     *
     * If there aren't enough slots within the current deadline, the deadline
     * is extended day-by-day until enough slots are found or the max extension
     * limit is reached.
     *
     * @param Goal $goal                  The goal owning the tasks.
     * @param Collection<Task> $tasks     Tasks to reschedule (sorted by order).
     * @param string $timezone            IANA timezone identifier.
     * @param int $maxExtensionDays       Max days to extend the deadline.
     * @param string|null $afterDatetime  If set, only slots STRICTLY AFTER this UTC datetime
     *                                    are considered. Used by postpone to ensure the task
     *                                    moves forward (skips its current slot and any earlier).
     * @return array{
     *   rescheduled: int,
     *   orphaned: int,
     *   deadline_extended: bool,
     *   new_deadline: string|null,
     *   original_deadline: string|null,
     *   warning: string|null
     * }
     *
     * @throws \RuntimeException If no slots can be generated even after max extension.
     */
    public function reschedule(
        Goal $goal,
        Collection $tasks,
        string $timezone,
        int $maxExtensionDays = self::DEFAULT_MAX_EXTENSION_DAYS,
        ?string $afterDatetime = null,
    ): array {
        $tasksCount = $tasks->count();

        if ($tasksCount === 0) {
            return $this->buildResult(0, 0, false);
        }

        // Collect IDs of tasks being rescheduled so we exclude them from "occupied" slots.
        $reschedulingIds = $tasks->pluck('id')->all();

        // Occupied slots = tasks in this goal that are completed (or locked)
        // and are NOT in the rescheduling set.
        $occupiedDatetimes = $this->getOccupiedSlotDatetimes($goal, $reschedulingIds);

        // Try to find enough free slots, extending deadline if needed.
        $originalDeadline = $goal->deadline->copy();
        $deadlineExtended = false;
        $freeSlots = [];
        $extensionDays = 0;

        // If afterDatetime is set (postpone), only consider slots strictly after it.
        // This ensures the postponed task actually moves forward in time.
        $afterCutoff = $afterDatetime ? Carbon::parse($afterDatetime) : null;

        while (count($freeSlots) < $tasksCount && $extensionDays <= $maxExtensionDays) {
            $allSlots = $this->scheduling->generateSlots($goal, null, $timezone);

            $freeSlots = array_values(array_filter(
                $allSlots,
                function (array $slot) use ($occupiedDatetimes, $afterCutoff) {
                    // Exclude occupied slots (completed/locked tasks).
                    if (in_array($slot['datetime'], $occupiedDatetimes, true)) {
                        return false;
                    }

                    // If afterCutoff is set, exclude slots at or before it.
                    if ($afterCutoff !== null && !Carbon::parse($slot['datetime'])->gt($afterCutoff)) {
                        return false;
                    }

                    return true;
                },
            ));

            if (count($freeSlots) >= $tasksCount) {
                break;
            }

            // Not enough slots — extend deadline by 1 day and retry.
            $extensionDays++;
            $goal->deadline = $goal->deadline->copy()->addDay();
            $deadlineExtended = true;

            Log::info('ReschedulingPipeline: extending deadline', [
                'goal_id' => $goal->id,
                'extension_day' => $extensionDays,
                'new_deadline' => $goal->deadline->toDateString(),
            ]);
        }

        // Assign tasks to free slots within a transaction.
        $rescheduled = 0;
        $orphaned = 0;

        DB::transaction(function () use (
            $goal,
            $tasks,
            $freeSlots,
            $deadlineExtended,
            &$rescheduled,
            &$orphaned,
        ) {
            $slotIndex = 0;

            foreach ($tasks as $task) {
                if ($slotIndex < count($freeSlots)) {
                    $newScheduledAt = $freeSlots[$slotIndex]['datetime'];

                    $task->update(['scheduled_at' => $newScheduledAt]);
                    $this->notificationScheduler->cancelTaskNotifications($task->id);

                    $slotIndex++;
                    $rescheduled++;
                } else {
                    // No slot available — task becomes orphaned.
                    $orphaned++;
                }
            }

            // Persist deadline extension if it changed.
            if ($deadlineExtended) {
                $goal->save();
            }
        });

        $warning = null;
        if ($orphaned > 0) {
            $warning = "No hay disponibilidad suficiente con tu horario actual. {$orphaned} tarea(s) no pudieron ser reprogramadas.";
        }

        return $this->buildResult(
            $rescheduled,
            $orphaned,
            $deadlineExtended,
            $deadlineExtended ? $goal->deadline->toDateString() : null,
            $deadlineExtended ? $originalDeadline->toDateString() : null,
            $warning,
        );
    }

    /**
     * Get UTC datetime strings of slots already occupied by non-reschedulable tasks.
     *
     * @param Goal $goal
     * @param array<int> $excludeTaskIds  Tasks being rescheduled (exclude them).
     * @return array<string>
     */
    private function getOccupiedSlotDatetimes(Goal $goal, array $excludeTaskIds): array
    {
        return $goal->tasks()
            ->whereNotIn('id', $excludeTaskIds)
            ->where(function ($query) {
                $query->where('status', Task::STATUS_COMPLETED)
                    ->orWhere('is_locked', true);
            })
            ->pluck('scheduled_at')
            ->map(fn($dt) => Carbon::parse($dt)->format('Y-m-d H:i:s'))
            ->all();
    }

    /**
     * Build a standardized result array.
     */
    private function buildResult(
        int $rescheduled,
        int $orphaned,
        bool $deadlineExtended,
        ?string $newDeadline = null,
        ?string $originalDeadline = null,
        ?string $warning = null,
    ): array {
        return [
            'rescheduled' => $rescheduled,
            'orphaned' => $orphaned,
            'deadline_extended' => $deadlineExtended,
            'new_deadline' => $newDeadline,
            'original_deadline' => $originalDeadline,
            'warning' => $warning,
        ];
    }
}
