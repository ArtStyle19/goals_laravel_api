<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesTimezone;
use App\Models\Goal;
use App\Models\Task;
use App\Services\TaskActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TaskController extends Controller
{
    use ResolvesTimezone;

    public function __construct(
        private TaskActionService $taskActionService,
    ) {}
    /**
     * Display a listing of tasks for a goal.
     */
    #[OA\Get(
        path: '/goals/{goalId}/tasks',
        operationId: 'listGoalTasks',
        summary: 'Listar tareas de un objetivo',
        description: 'Devuelve todas las tareas de un objetivo ordenadas por fecha programada, con información de duración.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'goalId', in: 'path', required: true, description: 'ID del objetivo', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de tareas',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'tasks',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/TaskDetail')
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Objetivo no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ]
    )]
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
    #[OA\Get(
        path: '/tasks/{taskId}',
        operationId: 'showTask',
        summary: 'Ver detalle de una tarea',
        description: 'Devuelve información detallada de una tarea incluyendo el título del objetivo padre.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, description: 'ID de la tarea', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalle de la tarea',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'task', ref: '#/components/schemas/TaskDetail'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Tarea no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ]
    )]
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
    #[OA\Put(
        path: '/tasks/{taskId}',
        operationId: 'updateTask',
        summary: 'Actualizar notas de tarea',
        description: 'Permite actualizar las notas personales de una tarea.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, description: 'ID de la tarea', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Revisar el capítulo 3 antes de la sesión'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarea actualizada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Tarea actualizada'),
                        new OA\Property(property: 'task', ref: '#/components/schemas/Task'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Tarea no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
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
    #[OA\Post(
        path: '/tasks/{taskId}/complete',
        operationId: 'completeTask',
        summary: 'Completar tarea',
        description: 'Marca una tarea como completada. Opcionalmente se pueden agregar notas. No se puede completar una tarea ya completada.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, description: 'ID de la tarea', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Completado con éxito, tema bien comprendido'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarea completada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Tarea completada'),
                        new OA\Property(property: 'task', ref: '#/components/schemas/Task'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Tarea no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(
                response: 422,
                description: 'Tarea ya completada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'La tarea ya está completada'),
                    ]
                )
            ),
        ]
    )]
    public function complete(Request $request, Task $task): JsonResponse
    {
        // Ensure user owns this task's goal
        if ($task->goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->taskActionService->complete($task, $validated['notes'] ?? null);

            return response()->json([
                'message' => $result['message'],
                'task' => $result['task'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Mark task as skipped.
     */
    #[OA\Post(
        path: '/tasks/{taskId}/skip',
        operationId: 'skipTask',
        summary: 'Saltar tarea',
        description: 'Marca una tarea programada como saltada (skipped). Solo aplica a tareas en estado scheduled. Se puede incluir una razón.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, description: 'ID de la tarea', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'No tuve tiempo hoy'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarea saltada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Tarea saltada'),
                        new OA\Property(property: 'task', ref: '#/components/schemas/Task'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedError')),
            new OA\Response(response: 403, description: 'No autorizado', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Tarea no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(
                response: 422,
                description: 'Solo se pueden saltar tareas programadas',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Solo se pueden saltar tareas programadas'),
                    ]
                )
            ),
        ]
    )]
    public function skip(Request $request, Task $task): JsonResponse
    {
        // Ensure user owns this task's goal
        if ($task->goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->taskActionService->skip($task, $validated['reason'] ?? null);

            return response()->json([
                'message' => $result['message'],
                'task' => $result['task'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Postpone a scheduled task to the next available slot.
     */
    #[OA\Post(
        path: '/tasks/{taskId}/postpone',
        operationId: 'postponeTask',
        summary: 'Posponer tarea',
        description: 'Mueve una tarea programada al siguiente slot disponible. Las tareas posteriores se desplazan en cascada. Si no hay slots disponibles, el deadline del objetivo se extiende automáticamente.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, description: 'ID de la tarea', schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'No tengo tiempo hoy'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarea pospuesta',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Tarea pospuesta'),
                        new OA\Property(property: 'task', ref: '#/components/schemas/Task'),
                        new OA\Property(
                            property: 'cascade',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'rescheduled', type: 'integer'),
                                new OA\Property(property: 'deadline_extended', type: 'boolean'),
                                new OA\Property(property: 'new_deadline', type: 'string', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado'),
            new OA\Response(response: 403, description: 'No autorizado'),
            new OA\Response(response: 404, description: 'Tarea no encontrada'),
            new OA\Response(response: 422, description: 'No se puede posponer'),
        ]
    )]
    public function postpone(Request $request, Task $task): JsonResponse
    {
        if ($task->goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $timezone = $this->resolveTimezone($request);

        try {
            $result = $this->taskActionService->postpone(
                $task,
                $timezone,
                $validated['reason'] ?? null,
            );

            return response()->json([
                'message' => $result['message'],
                'task' => $result['task'],
                'cascade' => $result['cascade'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Recover a skipped task — re-schedule it to the next available slot.
     */
    #[OA\Post(
        path: '/tasks/{taskId}/recover',
        operationId: 'recoverTask',
        summary: 'Recuperar tarea omitida',
        description: 'Devuelve una tarea omitida al estado programado y le asigna el siguiente slot disponible. Las tareas posteriores se desplazan en cascada.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, description: 'ID de la tarea', schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(ref: '#/components/parameters/X-Timezone'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarea recuperada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Tarea recuperada'),
                        new OA\Property(property: 'task', ref: '#/components/schemas/Task'),
                        new OA\Property(
                            property: 'cascade',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'rescheduled', type: 'integer'),
                                new OA\Property(property: 'deadline_extended', type: 'boolean'),
                                new OA\Property(property: 'new_deadline', type: 'string', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado'),
            new OA\Response(response: 403, description: 'No autorizado'),
            new OA\Response(response: 404, description: 'Tarea no encontrada'),
            new OA\Response(response: 422, description: 'No se puede recuperar'),
        ]
    )]
    public function recover(Request $request, Task $task): JsonResponse
    {
        if ($task->goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $timezone = $this->resolveTimezone($request);

        try {
            $result = $this->taskActionService->recover($task, $timezone);

            return response()->json([
                'message' => $result['message'],
                'task' => $result['task'],
                'cascade' => $result['cascade'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
