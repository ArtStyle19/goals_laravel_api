<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\Task;
use App\Services\PlannerService;
use App\Services\ReplanService;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    public function __construct(
        private SchedulingService $schedulingService,
        private PlannerService $plannerService,
        private ReplanService $replanService
    ) {}

    /**
     * Display a listing of the user's goals.
     */
    public function index(Request $request): JsonResponse
    {
        $goals = $request->user()
            ->goals()
            ->withCount('tasks')
            ->withCount(['tasks as completed_tasks_count' => function ($query) {
                $query->where('status', 'completed');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'goals' => $goals,
        ]);
    }

    /**
     * Store a newly created goal.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'deadline' => ['required', 'date', 'after:today'],
            'session_duration_minutes' => ['required', 'integer', Rule::in(Goal::VALID_SESSION_DURATIONS)],
            'availability_rules' => ['required', 'array', 'min:1'],
            'availability_rules.*.day' => ['required', 'string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'availability_rules.*.start' => ['required', 'date_format:H:i'],
            'availability_rules.*.end' => ['required', 'date_format:H:i', 'after:availability_rules.*.start'],
        ]);

        // Validate that blocks are multiples of session duration
        $blockValidation = $this->schedulingService->validateBlocks(
            $validated['availability_rules'],
            $validated['session_duration_minutes']
        );

        if (!$blockValidation['valid']) {
            return response()->json([
                'message' => 'Los bloques de disponibilidad no son válidos',
                'errors' => ['availability_rules' => $blockValidation['errors']],
            ], 422);
        }

        // Create goal
        $goal = $request->user()->goals()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'deadline' => $validated['deadline'],
            'session_duration_minutes' => $validated['session_duration_minutes'],
            'availability_rules' => $validated['availability_rules'],
            'status' => Goal::STATUS_DRAFT,
        ]);

        // Calculate and save capacity
        $capacity = $this->schedulingService->calculateCapacity($goal);
        $goal->calculateCapacity($capacity['total_sessions']);

        // Build response
        $response = [
            'message' => 'Objetivo creado exitosamente',
            'goal' => $goal->fresh(),
            'capacity' => [
                'total_sessions' => $capacity['total_sessions'],
                'total_hours' => $capacity['total_hours'],
            ],
        ];

        // Include warnings if any
        if (!empty($blockValidation['warnings'])) {
            $response['warnings'] = $blockValidation['warnings'];
        }

        return response()->json($response, 201);
    }

    /**
     * Display the specified goal.
     */
    public function show(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $goal->load(['tasks' => function ($query) {
            $query->orderBy('order');
        }]);

        return response()->json([
            'goal' => $goal,
        ]);
    }

    /**
     * Update the specified goal.
     */
    public function update(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $goal->update($validated);

        return response()->json([
            'message' => 'Objetivo actualizado',
            'goal' => $goal->fresh(),
        ]);
    }

    /**
     * Remove the specified goal.
     */
    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $goal->delete();

        return response()->json([
            'message' => 'Objetivo eliminado',
        ]);
    }

    /**
     * Add tasks manually to a goal.
     */
    public function addTasks(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Only allow adding tasks to draft goals
        if (!$goal->isDraft()) {
            return response()->json([
                'message' => 'Solo se pueden agregar tareas a objetivos en estado draft',
            ], 422);
        }

        $validated = $request->validate([
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string'],
        ]);

        // Generate slots
        $capacity = $this->schedulingService->calculateCapacity($goal);
        
        if (count($validated['tasks']) > $capacity['total_sessions']) {
            return response()->json([
                'message' => 'No hay suficientes slots disponibles',
                'available_slots' => $capacity['total_sessions'],
                'requested_tasks' => count($validated['tasks']),
            ], 422);
        }

        // Prepare tasks with order
        $tasksData = [];
        foreach ($validated['tasks'] as $index => $taskData) {
            $tasksData[] = [
                'title' => $taskData['title'],
                'description' => $taskData['description'] ?? null,
                'order' => $index + 1,
            ];
        }

        // Assign dates
        $tasksWithDates = $this->schedulingService->assignDates($tasksData, $capacity['slots']);

        // Create tasks in database
        DB::transaction(function () use ($goal, $tasksWithDates) {
            foreach ($tasksWithDates as $taskData) {
                $goal->tasks()->create([
                    'title' => $taskData['title'],
                    'description' => $taskData['description'],
                    'order' => $taskData['order'],
                    'scheduled_at' => $taskData['scheduled_at'],
                    'status' => Task::STATUS_SCHEDULED,
                ]);
            }

            // Activate the goal
            $goal->activate();
        });

        return response()->json([
            'message' => 'Tareas agregadas exitosamente',
            'goal' => $goal->fresh()->load('tasks'),
            'stats' => [
                'total_tasks' => count($tasksWithDates),
                'total_hours' => round((count($tasksWithDates) * $goal->session_duration_minutes) / 60, 1),
            ],
        ], 201);
    }

    /**
     * Get goal statistics.
     */
    public function stats(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $totalTasks = $goal->tasks()->count();
        $completedTasks = $goal->tasks()->where('status', 'completed')->count();
        $skippedTasks = $goal->tasks()->where('status', 'skipped')->count();
        $pendingTasks = $goal->tasks()->where('status', 'scheduled')->count();

        $progressPercentage = $totalTasks > 0 
            ? round(($completedTasks / $totalTasks) * 100, 1) 
            : 0;

        $hoursCompleted = round(($completedTasks * $goal->session_duration_minutes) / 60, 1);
        $hoursRemaining = round(($pendingTasks * $goal->session_duration_minutes) / 60, 1);

        $daysUntilDeadline = now()->diffInDays($goal->deadline, false);

        // Check if on track
        $capacityRemaining = $this->schedulingService->calculateCapacity($goal);
        $onTrack = $capacityRemaining['total_sessions'] >= $pendingTasks;

        return response()->json([
            'stats' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'skipped_tasks' => $skippedTasks,
                'pending_tasks' => $pendingTasks,
                'progress_percentage' => $progressPercentage,
                'total_hours_planned' => $goal->total_hours,
                'hours_completed' => $hoursCompleted,
                'hours_remaining' => $hoursRemaining,
                'days_until_deadline' => max(0, $daysUntilDeadline),
                'on_track' => $onTrack,
            ],
        ]);
    }

    /**
     * Generate tasks for a goal using LLM.
     */
    public function generate(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Only allow generating tasks for draft goals
        if (!$goal->isDraft()) {
            return response()->json([
                'success' => false,
                'error' => 'Solo se pueden generar tareas para objetivos en estado draft',
                'suggestion' => 'Crea un nuevo objetivo o elimina las tareas existentes',
            ], 422);
        }

        // Generate plan using LLM
        $result = $this->plannerService->generatePlan($goal);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'suggestion' => 'Intenta de nuevo o agrega tareas manualmente',
                'manual_endpoint' => "/api/goals/{$goal->id}/tasks",
            ], 422);
        }

        // Create tasks in database
        DB::transaction(function () use ($goal, $result) {
            foreach ($result['tasks'] as $taskData) {
                $goal->tasks()->create([
                    'title' => $taskData['title'],
                    'description' => $taskData['description'] ?? null,
                    'order' => $taskData['order'],
                    'scheduled_at' => $taskData['scheduled_at'],
                    'status' => Task::STATUS_SCHEDULED,
                ]);
            }

            // Activate the goal
            $goal->activate();
        });

        $goal->load('tasks');

        return response()->json([
            'success' => true,
            'message' => 'Plan generado exitosamente',
            'goal' => $goal,
            'stats' => [
                'total_tasks' => count($result['tasks']),
                'total_hours' => round((count($result['tasks']) * $goal->session_duration_minutes) / 60, 1),
            ],
            'warning' => $result['warning'],
        ], 201);
    }

    /**
     * Mark goal as completed.
     */
    public function complete(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($goal->isCompleted()) {
            return response()->json([
                'message' => 'El objetivo ya está completado',
            ], 422);
        }

        $goal->complete();

        return response()->json([
            'message' => 'Objetivo completado',
            'goal' => $goal->fresh(),
        ]);
    }

    /**
     * Update goal availability rules and replan tasks.
     */
    public function updateAvailability(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Only allow updating active goals
        if (!$goal->isActive()) {
            return response()->json([
                'message' => 'Solo se puede cambiar la disponibilidad de objetivos activos',
            ], 422);
        }

        $validated = $request->validate([
            'availability_rules' => ['required', 'array', 'min:1'],
            'availability_rules.*.day' => ['required', 'string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'availability_rules.*.start' => ['required', 'date_format:H:i'],
            'availability_rules.*.end' => ['required', 'date_format:H:i', 'after:availability_rules.*.start'],
        ]);

        // Validate that blocks are multiples of session duration
        $blockValidation = $this->schedulingService->validateBlocks(
            $validated['availability_rules'],
            $goal->session_duration_minutes
        );

        if (!$blockValidation['valid']) {
            return response()->json([
                'message' => 'Los bloques de disponibilidad no son válidos',
                'errors' => ['availability_rules' => $blockValidation['errors']],
            ], 422);
        }

        // Update availability rules
        $goal->update([
            'availability_rules' => $validated['availability_rules'],
        ]);

        // Recalculate capacity
        $capacity = $this->replanService->recalculateCapacity($goal);

        // Replan pending tasks
        $replanResult = $this->replanService->replan($goal);

        $goal->load('tasks');

        // Build response
        $response = [
            'message' => 'Disponibilidad actualizada',
            'goal' => $goal,
            'capacity' => $capacity,
            'replan' => [
                'rescheduled_count' => $replanResult['rescheduled_count'],
                'message' => $replanResult['message'],
                'warning' => $replanResult['warning'],
            ],
        ];

        // Include block validation warnings if any
        if (!empty($blockValidation['warnings'])) {
            $response['block_warnings'] = $blockValidation['warnings'];
        }

        return response()->json($response);
    }

    /**
     * Update goal deadline and replan tasks.
     */
    public function updateDeadline(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Only allow updating active goals
        if (!$goal->isActive()) {
            return response()->json([
                'message' => 'Solo se puede cambiar el deadline de objetivos activos',
            ], 422);
        }

        $validated = $request->validate([
            'deadline' => ['required', 'date', 'after:today'],
        ]);

        // Update deadline
        $goal->update([
            'deadline' => $validated['deadline'],
        ]);

        // Recalculate capacity
        $capacity = $this->replanService->recalculateCapacity($goal);

        // Replan pending tasks
        $replanResult = $this->replanService->replan($goal);

        // Check if there are more tasks than available slots
        $pendingTasksCount = $goal->tasks()
            ->where('status', Task::STATUS_SCHEDULED)
            ->count();
        
        $warning = null;
        if ($capacity['total_sessions'] < $pendingTasksCount) {
            $warning = sprintf(
                'Solo hay %d slots disponibles para %d tareas pendientes',
                $capacity['total_sessions'],
                $pendingTasksCount
            );
        }

        $goal->load('tasks');

        return response()->json([
            'message' => 'Deadline actualizado',
            'goal' => $goal,
            'capacity' => $capacity,
            'replan' => [
                'rescheduled_count' => $replanResult['rescheduled_count'],
                'message' => $replanResult['message'],
            ],
            'warning' => $warning ?? $replanResult['warning'],
        ]);
    }
}
