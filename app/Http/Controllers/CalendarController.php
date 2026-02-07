<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesTimezone;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CalendarController extends Controller
{
    use ResolvesTimezone;

    /**
     * Get calendar view with tasks in a date range.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        $tasks = Task::query()
            ->whereHas('goal', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->whereBetween('scheduled_at', [$from, $to])
            ->with('goal:id,title,session_duration_minutes')
            ->orderBy('scheduled_at')
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'goal_id' => $task->goal_id,
                    'goal_title' => $task->goal->title,
                    'title' => $task->title,
                    'description' => $task->description,
                    'scheduled_at' => $task->scheduled_at->toIso8601String(),
                    'end_at' => $task->end_at->toIso8601String(),
                    'duration_minutes' => $task->goal->session_duration_minutes,
                    'status' => $task->status,
                    'is_locked' => $task->is_locked,
                ];
            });

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'tasks' => $tasks,
            'total' => $tasks->count(),
        ]);
    }

    /**
     * Get today's tasks.
     *
     * Uses X-Timezone header to determine what "today" means for the user.
     * Converts the user's local day boundaries to UTC for querying.
     */
    public function today(Request $request): JsonResponse
    {
        $timezone = $this->resolveTimezone($request);

        // "Today" in the user's timezone, converted to UTC boundaries
        $todayStart = Carbon::today($timezone)->utc();
        $todayEnd = Carbon::tomorrow($timezone)->utc();

        $tasks = Task::query()
            ->whereHas('goal', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->where('scheduled_at', '>=', $todayStart)
            ->where('scheduled_at', '<', $todayEnd)
            ->with('goal:id,title,session_duration_minutes')
            ->orderBy('scheduled_at')
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'goal_id' => $task->goal_id,
                    'goal_title' => $task->goal->title,
                    'title' => $task->title,
                    'description' => $task->description,
                    'scheduled_at' => $task->scheduled_at->toIso8601String(),
                    'end_at' => $task->end_at->toIso8601String(),
                    'duration_minutes' => $task->goal->session_duration_minutes,
                    'status' => $task->status,
                ];
            });

        $totalMinutes = $tasks->sum('duration_minutes');

        return response()->json([
            'date' => Carbon::today($timezone)->toDateString(),
            'tasks' => $tasks,
            'total_tasks' => $tasks->count(),
            'total_minutes' => $totalMinutes,
            'total_hours' => round($totalMinutes / 60, 1),
        ]);
    }

    /**
     * Get next upcoming task.
     *
     * Since scheduled_at is stored in UTC, we compare against UTC now.
     */
    public function next(Request $request): JsonResponse
    {
        $now = Carbon::now('UTC');

        $task = Task::query()
            ->whereHas('goal', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>', $now)
            ->with('goal:id,title,session_duration_minutes')
            ->orderBy('scheduled_at')
            ->first();

        if (!$task) {
            return response()->json([
                'message' => 'No hay tareas próximas',
                'task' => null,
            ]);
        }

        $startsIn = $now->diffForHumans($task->scheduled_at, [
            'parts' => 2,
            'syntax' => Carbon::DIFF_RELATIVE_TO_NOW,
        ]);

        return response()->json([
            'task' => [
                'id' => $task->id,
                'goal_id' => $task->goal_id,
                'goal_title' => $task->goal->title,
                'title' => $task->title,
                'description' => $task->description,
                'scheduled_at' => $task->scheduled_at->toIso8601String(),
                'end_at' => $task->end_at->toIso8601String(),
                'duration_minutes' => $task->goal->session_duration_minutes,
            ],
            'starts_in' => $startsIn,
        ]);
    }
}
