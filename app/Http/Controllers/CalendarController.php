<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesTimezone;
use App\Models\Goal;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

class CalendarController extends Controller
{
    use ResolvesTimezone;

    /**
     * Get calendar view with tasks in a date range.
     */
    #[OA\Get(
        path: '/calendar',
        operationId: 'calendarIndex',
        summary: 'Vista de calendario',
        description: 'Devuelve las tareas programadas en un rango de fechas, ideal para renderizar un calendario. Las tareas incluyen información del objetivo padre.',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, description: 'Fecha de inicio (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-02-01')),
            new OA\Parameter(name: 'to', in: 'query', required: true, description: 'Fecha de fin (YYYY-MM-DD), debe ser >= from', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-02-28')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tareas en el rango de fechas',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'from', type: 'string', format: 'date', example: '2026-02-01'),
                        new OA\Property(property: 'to', type: 'string', format: 'date', example: '2026-02-28'),
                        new OA\Property(
                            property: 'tasks',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/CalendarTask')
                        ),
                        new OA\Property(property: 'total', type: 'integer', example: 12),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
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
    #[OA\Get(
        path: '/today',
        operationId: 'todayTasks',
        summary: 'Tareas de hoy',
        description: 'Devuelve todas las tareas programadas para hoy según la zona horaria del usuario (header X-Timezone). Incluye totales de tiempo.',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tareas del día',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-02-09'),
                        new OA\Property(
                            property: 'tasks',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'goal_id', type: 'integer', example: 1),
                                    new OA\Property(property: 'goal_title', type: 'string', example: 'Aprender Laravel'),
                                    new OA\Property(property: 'title', type: 'string', example: 'Sesión 5: Eloquent ORM'),
                                    new OA\Property(property: 'description', type: 'string', nullable: true),
                                    new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'end_at', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'duration_minutes', type: 'integer', example: 60),
                                    new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'completed', 'skipped']),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'total_tasks', type: 'integer', example: 3),
                        new OA\Property(property: 'total_minutes', type: 'integer', example: 180),
                        new OA\Property(property: 'total_hours', type: 'number', format: 'float', example: 3.0),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
        ]
    )]
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
    #[OA\Get(
        path: '/next',
        operationId: 'nextTask',
        summary: 'Próxima tarea',
        description: 'Devuelve la próxima tarea programada (scheduled) del usuario. Incluye un texto legible de cuánto falta para que inicie.',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Próxima tarea encontrada (o null si no hay)',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(
                            properties: [
                                new OA\Property(
                                    property: 'task',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 5),
                                        new OA\Property(property: 'goal_id', type: 'integer', example: 1),
                                        new OA\Property(property: 'goal_title', type: 'string', example: 'Aprender Laravel'),
                                        new OA\Property(property: 'title', type: 'string', example: 'Sesión 5: Eloquent ORM'),
                                        new OA\Property(property: 'description', type: 'string', nullable: true),
                                        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time'),
                                        new OA\Property(property: 'end_at', type: 'string', format: 'date-time'),
                                        new OA\Property(property: 'duration_minutes', type: 'integer', example: 60),
                                    ]
                                ),
                                new OA\Property(property: 'starts_in', type: 'string', example: '2 hours from now'),
                            ]
                        ),
                        new OA\Schema(
                            properties: [
                                new OA\Property(property: 'message', type: 'string', example: 'No hay tareas próximas'),
                                new OA\Property(property: 'task', type: 'string', nullable: true, example: null),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
        ]
    )]
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

    /**
     * Get global user statistics overview.
     */
    #[OA\Get(
        path: '/stats/overview',
        operationId: 'statsOverview',
        summary: 'Estadísticas generales',
        description: 'Devuelve estadísticas globales del usuario: metas, tareas, horas, rachas, y distribución por día de la semana.',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estadísticas globales del usuario',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'goals', type: 'object'),
                        new OA\Property(property: 'tasks', type: 'object'),
                        new OA\Property(property: 'time', type: 'object'),
                        new OA\Property(property: 'streak', type: 'object'),
                        new OA\Property(property: 'weekly_distribution', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'recent_activity', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado'),
        ]
    )]
    public function statsOverview(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $timezone = $this->resolveTimezone($request);
        $now = Carbon::now($timezone);

        // ─── Goals summary ───────────────────────
        $totalGoals = Goal::where('user_id', $userId)->count();
        $activeGoals = Goal::where('user_id', $userId)->where('status', 'active')->count();
        $completedGoals = Goal::where('user_id', $userId)->where('status', 'completed')->count();
        $draftGoals = Goal::where('user_id', $userId)->where('status', 'draft')->count();

        // ─── Tasks summary ───────────────────────
        $userTasksQuery = Task::whereHas('goal', fn($q) => $q->where('user_id', $userId));
        $totalTasks = (clone $userTasksQuery)->count();
        $completedTasks = (clone $userTasksQuery)->where('status', 'completed')->count();
        $skippedTasks = (clone $userTasksQuery)->where('status', 'skipped')->count();
        $pendingTasks = (clone $userTasksQuery)->where('status', 'scheduled')->count();
        $overdueTasks = (clone $userTasksQuery)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<', Carbon::now('UTC'))
            ->count();
        $completionRate = $totalTasks > 0
            ? round(($completedTasks / $totalTasks) * 100, 1)
            : 0;

        // ─── Time invested ───────────────────────
        $completedTasksWithDuration = Task::query()
            ->whereHas('goal', fn($q) => $q->where('user_id', $userId))
            ->where('status', 'completed')
            ->with('goal:id,session_duration_minutes')
            ->get();

        $totalMinutesInvested = $completedTasksWithDuration->sum(
            fn($task) => $task->goal->session_duration_minutes ?? 0
        );
        $totalHoursInvested = round($totalMinutesInvested / 60, 1);

        $pendingTasksWithDuration = Task::query()
            ->whereHas('goal', fn($q) => $q->where('user_id', $userId))
            ->where('status', 'scheduled')
            ->with('goal:id,session_duration_minutes')
            ->get();

        $totalMinutesRemaining = $pendingTasksWithDuration->sum(
            fn($task) => $task->goal->session_duration_minutes ?? 0
        );
        $totalHoursRemaining = round($totalMinutesRemaining / 60, 1);

        // ─── Streak calculation ──────────────────
        $streak = $this->calculateStreak($userId, $timezone);

        // ─── Weekly distribution (last 4 weeks) ──
        $fourWeeksAgo = $now->copy()->subWeeks(4)->startOfDay()->utc();
        $weeklyTasks = Task::query()
            ->whereHas('goal', fn($q) => $q->where('user_id', $userId))
            ->where('status', 'completed')
            ->where('completed_at', '>=', $fourWeeksAgo)
            ->get();

        $dayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        $distribution = array_fill(0, 7, 0);
        foreach ($weeklyTasks as $task) {
            // Carbon dayOfWeekIso: 1=Mon, 7=Sun
            $dayIndex = $task->completed_at->timezone($timezone)->dayOfWeekIso - 1;
            $distribution[$dayIndex]++;
        }
        $weeklyDistribution = [];
        $maxCount = max(1, max($distribution));
        for ($i = 0; $i < 7; $i++) {
            $weeklyDistribution[] = [
                'day' => $dayNames[$i],
                'count' => $distribution[$i],
                'percentage' => round(($distribution[$i] / $maxCount) * 100),
            ];
        }

        // ─── Recent activity (last 14 days) ──────
        $twoWeeksAgo = $now->copy()->subDays(14)->startOfDay()->utc();
        $recentDays = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i);
            $dayStart = $day->copy()->startOfDay()->utc();
            $dayEnd = $day->copy()->endOfDay()->utc();

            $dayCompleted = Task::query()
                ->whereHas('goal', fn($q) => $q->where('user_id', $userId))
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$dayStart, $dayEnd])
                ->count();

            $dayTotal = Task::query()
                ->whereHas('goal', fn($q) => $q->where('user_id', $userId))
                ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
                ->count();

            $recentDays[] = [
                'date' => $day->toDateString(),
                'day' => $dayNames[$day->dayOfWeekIso - 1],
                'completed' => $dayCompleted,
                'total' => $dayTotal,
            ];
        }

        // ─── Best day & average ──────────────────
        $completedPerDay = collect($recentDays)->pluck('completed');
        $bestDayCount = $completedPerDay->max() ?? 0;
        $avgPerDay = round($completedPerDay->avg() ?? 0, 1);

        return response()->json([
            'goals' => [
                'total' => $totalGoals,
                'active' => $activeGoals,
                'completed' => $completedGoals,
                'draft' => $draftGoals,
            ],
            'tasks' => [
                'total' => $totalTasks,
                'completed' => $completedTasks,
                'skipped' => $skippedTasks,
                'pending' => $pendingTasks,
                'overdue' => $overdueTasks,
                'completion_rate' => $completionRate,
            ],
            'time' => [
                'hours_invested' => $totalHoursInvested,
                'minutes_invested' => $totalMinutesInvested,
                'hours_remaining' => $totalHoursRemaining,
                'minutes_remaining' => $totalMinutesRemaining,
            ],
            'streak' => $streak,
            'weekly_distribution' => $weeklyDistribution,
            'recent_activity' => $recentDays,
            'summary' => [
                'best_day_count' => $bestDayCount,
                'avg_per_day' => $avgPerDay,
            ],
        ]);
    }

    /**
     * Calculate the user's current and best streak.
     */
    private function calculateStreak(int $userId, string $timezone): array
    {
        $now = Carbon::now($timezone);
        $currentStreak = 0;
        $checkDay = $now->copy();

        // Check if today has any completed tasks; if not, start from yesterday
        $todayStart = $checkDay->copy()->startOfDay()->utc();
        $todayEnd = $checkDay->copy()->endOfDay()->utc();
        $todayCompleted = Task::query()
            ->whereHas('goal', fn($q) => $q->where('user_id', $userId))
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$todayStart, $todayEnd])
            ->exists();

        if (!$todayCompleted) {
            $checkDay->subDay();
        }

        // Count consecutive days with completions going backwards
        for ($i = 0; $i < 365; $i++) {
            $dayStart = $checkDay->copy()->startOfDay()->utc();
            $dayEnd = $checkDay->copy()->endOfDay()->utc();

            $hasCompleted = Task::query()
                ->whereHas('goal', fn($q) => $q->where('user_id', $userId))
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$dayStart, $dayEnd])
                ->exists();

            if ($hasCompleted) {
                $currentStreak++;
                $checkDay->subDay();
            } else {
                break;
            }
        }

        // Calculate best streak (last 90 days for performance)
        $bestStreak = 0;
        $tempStreak = 0;
        $ninetyDaysAgo = $now->copy()->subDays(90);

        for ($i = 90; $i >= 0; $i--) {
            $day = $ninetyDaysAgo->copy()->addDays($i);
            $dayStart = $day->copy()->startOfDay()->utc();
            $dayEnd = $day->copy()->endOfDay()->utc();

            $hasCompleted = Task::query()
                ->whereHas('goal', fn($q) => $q->where('user_id', $userId))
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$dayStart, $dayEnd])
                ->exists();

            if ($hasCompleted) {
                $tempStreak++;
                $bestStreak = max($bestStreak, $tempStreak);
            } else {
                $tempStreak = 0;
            }
        }

        return [
            'current' => $currentStreak,
            'best' => $bestStreak,
        ];
    }
}
