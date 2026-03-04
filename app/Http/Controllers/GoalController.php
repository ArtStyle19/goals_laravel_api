<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesTimezone;
use App\Models\Goal;
use App\Models\Task;
use App\Services\PlannerService;
use App\Services\ReplanService;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class GoalController extends Controller
{
    use ResolvesTimezone;

    public function __construct(
        private SchedulingService $schedulingService,
        private PlannerService $plannerService,
        private ReplanService $replanService,
    ) {}

    /**
     * Display a listing of the user's goals.
     */
    #[OA\Get(
        path: '/goals',
        operationId: 'listGoals',
        summary: 'Listar objetivos del usuario',
        description: 'Devuelve todos los objetivos del usuario autenticado con conteos de tareas totales y completadas.',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de objetivos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'goals',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/GoalWithCounts')
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
        ]
    )]
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
    #[OA\Post(
        path: '/goals',
        operationId: 'createGoal',
        summary: 'Crear nuevo objetivo',
        description: 'Crea un objetivo en estado draft con reglas de disponibilidad semanal. Calcula automáticamente la capacidad (sesiones disponibles hasta el deadline).',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'deadline', 'session_duration_minutes', 'availability_rules'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Aprender Laravel'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Curso completo de Laravel desde cero hasta avanzado'),
                    new OA\Property(property: 'deadline', type: 'string', format: 'date', example: '2026-06-01', description: 'Debe ser posterior a hoy'),
                    new OA\Property(
                        property: 'session_duration_minutes',
                        type: 'integer',
                        enum: [30, 45, 60, 90, 120],
                        example: 60,
                        description: 'Duración de cada sesión de estudio'
                    ),
                    new OA\Property(
                        property: 'availability_rules',
                        type: 'array',
                        items: new OA\Items(ref: '#/components/schemas/AvailabilityRule'),
                        minItems: 1,
                        description: 'Bloques de disponibilidad semanal. Cada bloque debe ser múltiplo de session_duration_minutes.'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Objetivo creado exitosamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Objetivo creado exitosamente'),
                        new OA\Property(property: 'goal', ref: '#/components/schemas/Goal'),
                        new OA\Property(
                            property: 'capacity',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total_sessions', type: 'integer', example: 48),
                                new OA\Property(property: 'total_hours', type: 'number', format: 'float', example: 48.0),
                            ]
                        ),
                        new OA\Property(
                            property: 'warnings',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            description: 'Advertencias sobre bloques de disponibilidad (opcional)'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
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
        $timezone = $this->resolveTimezone($request);
        $capacity = $this->schedulingService->calculateCapacity($goal, null, $timezone);
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
    #[OA\Get(
        path: '/goals/{goalId}',
        operationId: 'showGoal',
        summary: 'Ver detalle de un objetivo',
        description: 'Devuelve un objetivo con todas sus tareas ordenadas por orden.',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalle del objetivo con tareas',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'goal', ref: '#/components/schemas/GoalWithTasks'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Objetivo no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ]
    )]
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
    #[OA\Put(
        path: '/goals/{goalId}',
        operationId: 'updateGoal',
        summary: 'Actualizar objetivo',
        description: 'Actualiza el título y/o descripción de un objetivo.',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Aprender Laravel Avanzado'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Enfoque en APIs REST y testing'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Objetivo actualizado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Objetivo actualizado'),
                        new OA\Property(property: 'goal', ref: '#/components/schemas/Goal'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Objetivo no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
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
    #[OA\Delete(
        path: '/goals/{goalId}',
        operationId: 'deleteGoal',
        summary: 'Eliminar objetivo',
        description: 'Elimina un objetivo y todas sus tareas asociadas (cascade).',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Objetivo eliminado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Objetivo eliminado'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Objetivo no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ]
    )]
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
    #[OA\Post(
        path: '/goals/{goalId}/tasks',
        operationId: 'addTasksToGoal',
        summary: 'Agregar tareas manualmente',
        description: 'Agrega tareas manualmente a un objetivo en estado draft. Las tareas se programan automáticamente en los slots de disponibilidad. El objetivo pasa a estado active.',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo (debe estar en draft)', schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['tasks'],
                properties: [
                    new OA\Property(
                        property: 'tasks',
                        type: 'array',
                        minItems: 1,
                        items: new OA\Items(
                            required: ['title'],
                            properties: [
                                new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Sesión 1: Introducción'),
                                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Conceptos básicos del tema'),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Tareas agregadas exitosamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Tareas agregadas exitosamente'),
                        new OA\Property(property: 'goal', ref: '#/components/schemas/GoalWithTasks'),
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total_tasks', type: 'integer', example: 10),
                                new OA\Property(property: 'total_hours', type: 'number', format: 'float', example: 10.0),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(
                response: 422,
                description: 'Error de validación o slots insuficientes',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'No hay suficientes slots disponibles'),
                        new OA\Property(property: 'available_slots', type: 'integer', example: 5),
                        new OA\Property(property: 'requested_tasks', type: 'integer', example: 10),
                    ]
                )
            ),
        ]
    )]
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
        $timezone = $this->resolveTimezone($request);
        $capacity = $this->schedulingService->calculateCapacity($goal, null, $timezone);

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
    #[OA\Get(
        path: '/goals/{goalId}/stats',
        operationId: 'goalStats',
        summary: 'Estadísticas del objetivo',
        description: 'Devuelve métricas de progreso: tareas completadas/pendientes, horas, porcentaje de avance y si va en tiempo.',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo', schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estadísticas del objetivo',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'stats', ref: '#/components/schemas/GoalStats'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Objetivo no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ]
    )]
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
        $timezone = $this->resolveTimezone($request);
        $capacityRemaining = $this->schedulingService->calculateCapacity($goal, null, $timezone);
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
    #[OA\Post(
        path: '/goals/{goalId}/generate',
        operationId: 'generateGoalPlan',
        summary: 'Generar plan con IA',
        description: 'Genera un plan de tareas usando LLM (IA) para un objetivo en estado draft. Las tareas se crean y programan automáticamente. El objetivo pasa a estado active.',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo (debe estar en draft)', schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Plan generado exitosamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Plan generado exitosamente'),
                        new OA\Property(property: 'goal', ref: '#/components/schemas/GoalWithTasks'),
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total_tasks', type: 'integer', example: 15),
                                new OA\Property(property: 'total_hours', type: 'number', format: 'float', example: 15.0),
                            ]
                        ),
                        new OA\Property(property: 'warning', type: 'string', nullable: true, example: null, description: 'Advertencia del planificador (si aplica)'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(
                response: 422,
                description: 'Error al generar plan o objetivo no está en draft',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'error', type: 'string', example: 'Solo se pueden generar tareas para objetivos en estado draft'),
                        new OA\Property(property: 'suggestion', type: 'string', example: 'Intenta de nuevo o agrega tareas manualmente'),
                        new OA\Property(property: 'manual_endpoint', type: 'string', example: '/api/goals/1/tasks'),
                    ]
                )
            ),
        ]
    )]
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
        $timezone = $this->resolveTimezone($request);
        $result = $this->plannerService->generatePlan($goal, $timezone);

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
    #[OA\Post(
        path: '/goals/{goalId}/complete',
        operationId: 'completeGoal',
        summary: 'Completar objetivo',
        description: 'Marca un objetivo como completado. No se puede completar un objetivo que ya está completado.',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Objetivo completado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Objetivo completado'),
                        new OA\Property(property: 'goal', ref: '#/components/schemas/Goal'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Objetivo no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(
                response: 422,
                description: 'Objetivo ya completado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'El objetivo ya está completado'),
                    ]
                )
            ),
        ]
    )]
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
     * Replan a goal using LLM, preserving completed tasks.
     */
    #[OA\Post(
        path: '/goals/{goalId}/replan',
        operationId: 'replanGoal',
        summary: 'Replanificar objetivo con IA',
        description: 'Regenera las tareas pendientes usando LLM. Las tareas completadas se mantienen como contexto para generar tareas nuevas que continúen el progreso. Las tareas pendientes y omitidas se eliminan. Funciona con objetivos activos y completados (estos se reactivan).',
        security: [['sanctum' => []]],
        tags: ['Goals'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo', schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Enfocarse más en la parte práctica', description: 'Nueva descripción del objetivo (opcional)'),
                    new OA\Property(property: 'deadline', type: 'string', format: 'date', nullable: true, example: '2026-06-01', description: 'Nueva fecha límite (opcional, debe ser futura)'),
                    new OA\Property(
                        property: 'availability_rules',
                        type: 'array',
                        nullable: true,
                        items: new OA\Items(ref: '#/components/schemas/AvailabilityRule'),
                        description: 'Nuevas reglas de disponibilidad (opcional)'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Replanificación exitosa',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Plan regenerado exitosamente'),
                        new OA\Property(property: 'goal', ref: '#/components/schemas/GoalWithTasks'),
                        new OA\Property(
                            property: 'replan_stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'completed_kept', type: 'integer', example: 5),
                                new OA\Property(property: 'deleted', type: 'integer', example: 10),
                                new OA\Property(property: 'generated', type: 'integer', example: 8),
                            ]
                        ),
                        new OA\Property(property: 'warning', type: 'string', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Objetivo no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(
                response: 422,
                description: 'Error en la replanificación',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'error', type: 'string', example: 'El deadline ha expirado'),
                        new OA\Property(property: 'error_code', type: 'string', nullable: true, example: 'deadline_expired'),
                    ]
                )
            ),
        ]
    )]
    public function replan(Request $request, Goal $goal): JsonResponse
    {
        // Ensure user owns this goal
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Only active or completed goals can be replanned
        if ($goal->isDraft()) {
            return response()->json([
                'success' => false,
                'error' => 'No se puede replanificar un objetivo en borrador. Usa la generación inicial.',
            ], 422);
        }

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'deadline' => ['nullable', 'date', 'after:today'],
            'availability_rules' => ['nullable', 'array', 'min:1'],
            'availability_rules.*.day' => ['required_with:availability_rules', 'string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'availability_rules.*.start' => ['required_with:availability_rules', 'date_format:H:i'],
            'availability_rules.*.end' => ['required_with:availability_rules', 'date_format:H:i', 'after:availability_rules.*.start'],
        ]);

        // Validate availability blocks if provided
        if (!empty($validated['availability_rules'])) {
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
        }

        $timezone = $this->resolveTimezone($request);
        $newDescription = $validated['description'] ?? null;
        $newDeadline = $validated['deadline'] ?? null;
        $newAvailabilityRules = $validated['availability_rules'] ?? null;

        $result = $this->replanService->replan($goal, $timezone, $newDescription, $newDeadline, $newAvailabilityRules);

        if (!$result['success']) {
            $response = [
                'success' => false,
                'error' => $result['error'],
            ];

            if (isset($result['error_code'])) {
                $response['error_code'] = $result['error_code'];
            }

            return response()->json($response, 422);
        }

        $goal->load('tasks');

        return response()->json([
            'success' => true,
            'message' => 'Plan regenerado exitosamente',
            'goal' => $goal,
            'replan_stats' => $result['replan_stats'],
            'warning' => $result['warning'],
        ], 201);
    }

    /**
     * Update goal availability rules and replan tasks.
     */
    // public function updateAvailability(Request $request, Goal $goal): JsonResponse
    // {
    //     // Ensure user owns this goal
    //     if ($goal->user_id !== $request->user()->id) {
    //         return response()->json(['message' => 'No autorizado'], 403);
    //     }

    //     // Only allow updating active goals
    //     if (!$goal->isActive()) {
    //         return response()->json([
    //             'message' => 'Solo se puede cambiar la disponibilidad de objetivos activos',
    //         ], 422);
    //     }

    //     $validated = $request->validate([
    //         'availability_rules' => ['required', 'array', 'min:1'],
    //         'availability_rules.*.day' => ['required', 'string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
    //         'availability_rules.*.start' => ['required', 'date_format:H:i'],
    //         'availability_rules.*.end' => ['required', 'date_format:H:i', 'after:availability_rules.*.start'],
    //     ]);

    //     // Validate that blocks are multiples of session duration
    //     $blockValidation = $this->schedulingService->validateBlocks(
    //         $validated['availability_rules'],
    //         $goal->session_duration_minutes
    //     );

    //     if (!$blockValidation['valid']) {
    //         return response()->json([
    //             'message' => 'Los bloques de disponibilidad no son válidos',
    //             'errors' => ['availability_rules' => $blockValidation['errors']],
    //         ], 422);
    //     }

    //     // Update availability rules
    //     $goal->update([
    //         'availability_rules' => $validated['availability_rules'],
    //     ]);

    //     // Recalculate capacity
    //     $capacity = $this->replanService->recalculateCapacity($goal);

    //     // Replan pending tasks
    //     $replanResult = $this->replanService->replan($goal);

    //     $goal->load('tasks');

    //     // Build response
    //     $response = [
    //         'message' => 'Disponibilidad actualizada',
    //         'goal' => $goal,
    //         'capacity' => $capacity,
    //         'replan' => [
    //             'rescheduled_count' => $replanResult['rescheduled_count'],
    //             'message' => $replanResult['message'],
    //             'warning' => $replanResult['warning'],
    //         ],
    //     ];

    //     // Include block validation warnings if any
    //     if (!empty($blockValidation['warnings'])) {
    //         $response['block_warnings'] = $blockValidation['warnings'];
    //     }

    //     return response()->json($response);
    // }

    /**
     * Update goal deadline and replan tasks.
     */


    // public function updateDeadline(Request $request, Goal $goal): JsonResponse
    // {
    //     // Ensure user owns this goal
    //     if ($goal->user_id !== $request->user()->id) {
    //         return response()->json(['message' => 'No autorizado'], 403);
    //     }

    //     // Only allow updating active goals
    //     if (!$goal->isActive()) {
    //         return response()->json([
    //             'message' => 'Solo se puede cambiar el deadline de objetivos activos',
    //         ], 422);
    //     }

    //     $validated = $request->validate([
    //         'deadline' => ['required', 'date', 'after:today'],
    //     ]);

    //     // Update deadline
    //     $goal->update([
    //         'deadline' => $validated['deadline'],
    //     ]);

    //     // Recalculate capacity
    //     $capacity = $this->replanService->recalculateCapacity($goal);

    //     // Replan pending tasks
    //     $replanResult = $this->replanService->replan($goal);

    //     // Check if there are more tasks than available slots
    //     $pendingTasksCount = $goal->tasks()
    //         ->where('status', Task::STATUS_SCHEDULED)
    //         ->count();

    //     $warning = null;
    //     if ($capacity['total_sessions'] < $pendingTasksCount) {
    //         $warning = sprintf(
    //             'Solo hay %d slots disponibles para %d tareas pendientes',
    //             $capacity['total_sessions'],
    //             $pendingTasksCount
    //         );
    //     }

    //     $goal->load('tasks');

    //     return response()->json([
    //         'message' => 'Deadline actualizado',
    //         'goal' => $goal,
    //         'capacity' => $capacity,
    //         'replan' => [
    //             'rescheduled_count' => $replanResult['rescheduled_count'],
    //             'message' => $replanResult['message'],
    //         ],
    //         'warning' => $warning ?? $replanResult['warning'],
    //     ]);
    // }
}
