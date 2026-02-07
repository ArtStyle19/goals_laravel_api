<?php

// namespace App\Services;

// use App\Models\Goal;
// use App\Models\Task;
// use Carbon\Carbon;
// use Illuminate\Support\Facades\DB;

// class ReplanService
// {
//     public function __construct(
//         private SchedulingService $scheduling
//     ) {}

//     /**
//      * Recalculate dates for pending tasks.
//      * Respects completed and locked tasks - they are not moved.
//      *
//      * @param Goal $goal
//      * @return array{success: bool, rescheduled_count: int, tasks: array, warning: ?string}
//      */
//     public function replan(Goal $goal): array
//     {
//         // 1. Get pending tasks (not completed, not locked)
//         $pendingTasks = $goal->tasks()
//             ->where('status', Task::STATUS_SCHEDULED)
//             ->where('is_locked', false)
//             ->orderBy('order')
//             ->get();

//         if ($pendingTasks->isEmpty()) {
//             return [
//                 'success' => true,
//                 'rescheduled_count' => 0,
//                 'tasks' => [],
//                 'warning' => null,
//                 'message' => 'No hay tareas pendientes para reprogramar',
//             ];
//         }

//         // 2. Generate new slots from TODAY (not from goal creation)
//         $slots = $this->scheduling->generateSlots($goal, Carbon::today());

//         // 3. Filter out slots that are already taken by locked/completed tasks
//         $reservedSlots = $this->getReservedSlots($goal);
//         $availableSlots = $this->filterAvailableSlots($slots, $reservedSlots);

//         // 4. Check if we have enough slots
//         $warning = null;
//         if (count($availableSlots) < $pendingTasks->count()) {
//             $warning = sprintf(
//                 'Solo hay %d slots disponibles para %d tareas pendientes. Algunas tareas no tendrán fecha asignada.',
//                 count($availableSlots),
//                 $pendingTasks->count()
//             );
//         }

//         // 5. Prepare tasks data for reassignment
//         $tasksData = $pendingTasks->map(function ($task) {
//             return [
//                 'id' => $task->id,
//                 'order' => $task->order,
//                 'title' => $task->title,
//                 'description' => $task->description,
//             ];
//         })->toArray();

//         // 6. Assign new dates
//         $updatedTasks = $this->scheduling->assignDates($tasksData, $availableSlots);

//         // 7. Update in database (only tasks with valid scheduled_at)
//         DB::transaction(function () use ($updatedTasks) {
//             foreach ($updatedTasks as $taskData) {
//                 // Only update if we have a valid scheduled_at
//                 if ($taskData['scheduled_at'] !== null) {
//                     Task::where('id', $taskData['id'])
//                         ->update(['scheduled_at' => $taskData['scheduled_at']]);
//                 }
//             }
//         });

//         // 8. Return statistics
//         $rescheduledCount = count(array_filter($updatedTasks, fn($t) => $t['scheduled_at'] !== null));

//         return [
//             'success' => true,
//             'rescheduled_count' => $rescheduledCount,
//             'tasks' => $updatedTasks,
//             'warning' => $warning,
//             'message' => sprintf('%d tareas fueron reprogramadas', $rescheduledCount),
//         ];
//     }

//     /**
//      * Get datetime strings of slots that are reserved by locked or completed tasks.
//      *
//      * @param Goal $goal
//      * @return array Array of datetime strings
//      */
//     private function getReservedSlots(Goal $goal): array
//     {
//         return $goal->tasks()
//             ->where(function ($query) {
//                 $query->where('status', '!=', Task::STATUS_SCHEDULED)
//                     ->orWhere('is_locked', true);
//             })
//             ->whereNotNull('scheduled_at')
//             ->pluck('scheduled_at')
//             ->map(fn($dt) => Carbon::parse($dt)->toDateTimeString())
//             ->toArray();
//     }

//     /**
//      * Filter out slots that are already reserved.
//      *
//      * @param array $slots All available slots
//      * @param array $reservedSlots Datetime strings of reserved slots
//      * @return array Available slots
//      */
//     private function filterAvailableSlots(array $slots, array $reservedSlots): array
//     {
//         return array_values(array_filter($slots, function ($slot) use ($reservedSlots) {
//             return !in_array($slot['datetime'], $reservedSlots);
//         }));
//     }

//     /**
//      * Recalculate goal capacity after availability or deadline change.
//      *
//      * @param Goal $goal
//      * @return array{total_sessions: int, total_hours: float}
//      */
//     public function recalculateCapacity(Goal $goal): array
//     {
//         $capacity = $this->scheduling->calculateCapacity($goal, Carbon::today());

//         // Update goal with new capacity
//         $goal->update([
//             'total_sessions' => $capacity['total_sessions'],
//             'total_hours' => $capacity['total_hours'],
//         ]);

//         return [
//             'total_sessions' => $capacity['total_sessions'],
//             'total_hours' => $capacity['total_hours'],
//         ];
//     }
// }
