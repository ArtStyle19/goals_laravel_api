<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ReplanService - Replanificación inteligente con LLM
 *
 * Responsabilidad: Regenerar el plan de tareas de un objetivo activo o completado
 * usando el LLM, respetando las tareas ya completadas como contexto.
 *
 * Flujo:
 * 1. Validar estado del goal (active|completed) y deadline
 * 2. Obtener tareas completadas (título + descripción) como contexto
 * 3. Calcular capacidad restante (slots disponibles)
 * 4. Construir prompts con contexto de progreso
 * 5. Llamar al LLM para generar tareas nuevas
 * 6. Validar y asignar fechas a las tareas generadas
 * 7. En transacción: borrar pendientes/skipped, crear nuevas, reactivar si completed
 *
 * Delegaciones:
 * - LLMService: comunicación con OpenAI (SRP)
 * - SchedulingService: cálculo de slots y asignación de fechas (SRP)
 */
class ReplanService
{
    public function __construct(
        private LLMService $llm,
        private SchedulingService $scheduling,
    ) {}

    /**
     * Replanificar un objetivo usando el LLM.
     *
     * @param Goal $goal El objetivo a replanificar
     * @param string $timezone IANA timezone del usuario
     * @param string|null $newDescription Descripción actualizada (opcional)
     * @param string|null $newDeadline Nueva fecha límite (Y-m-d, opcional)
     * @param array|null $newAvailabilityRules Nuevas reglas de disponibilidad (opcional)
     * @return array{success: bool, tasks: array, error: ?string, warning: ?string, replan_stats: ?array}
     */
    public function replan(
        Goal $goal,
        string $timezone = 'UTC',
        ?string $newDescription = null,
        ?string $newDeadline = null,
        ?array $newAvailabilityRules = null,
    ): array {
        // 0. Validate LLM configuration
        if (!$this->llm->isConfigured()) {
            return $this->errorResult('El servicio de LLM no está configurado. Configura OPENAI_API_KEY.');
        }

        // 1. Validate goal state
        if ($goal->isDraft()) {
            return $this->errorResult('No se puede replanificar un objetivo en borrador. Usa la generación inicial.');
        }

        // 2. Apply deadline change before validation (if provided)
        if ($newDeadline !== null) {
            $goal->deadline = Carbon::parse($newDeadline);
        }

        // 3. Apply availability change before capacity calculation (if provided)
        if ($newAvailabilityRules !== null) {
            $goal->availability_rules = $newAvailabilityRules;
        }

        // 4. Check deadline — if past, require a valid future deadline
        if ($goal->deadline->lt(Carbon::today($timezone))) {
            return $this->errorResult(
                'El deadline ha expirado. Actualiza la fecha límite antes de replanificar.',
                'deadline_expired'
            );
        }

        // 5. Get completed tasks as context for the LLM
        $completedTasks = $goal->completedTasks()
            ->orderBy('order')
            ->get(['title', 'description']);

        // 6. Calculate remaining capacity (uses the possibly-updated deadline and availability)
        $capacity = $this->scheduling->calculateCapacity($goal, null, $timezone);

        if ($capacity['total_sessions'] === 0) {
            return $this->errorResult('No hay slots disponibles hasta el deadline. Verifica la disponibilidad y la fecha límite.');
        }

        // 7. Build specialized prompts
        $systemPrompt = $this->buildSystemPrompt($capacity['total_sessions'], $completedTasks->count());
        $userPrompt = $this->buildUserPrompt($goal, $capacity, $completedTasks, $newDescription);

        Log::info('ReplanService: generating replan', [
            'goal_id' => $goal->id,
            'completed_tasks' => $completedTasks->count(),
            'available_slots' => $capacity['total_sessions'],
            'deadline_changed' => $newDeadline !== null,
            'availability_changed' => $newAvailabilityRules !== null,
        ]);

        // 8. Call LLM
        $response = $this->llm->call($systemPrompt, $userPrompt);

        if ($response === null) {
            return $this->errorResult('Error al comunicarse con el LLM. Intenta de nuevo.');
        }

        // 9. Extract and validate JSON
        $data = $this->llm->extractJson($response);

        if ($data === null || !isset($data['tasks'])) {
            return $this->errorResult('El LLM no devolvió un formato válido. Intenta de nuevo.');
        }

        $validation = $this->llm->validateTasks($data['tasks'], $capacity['total_sessions']);

        if (!$validation['valid']) {
            return $this->errorResult($validation['error']);
        }

        // 10. Assign sequential order AFTER completed tasks
        $startingOrder = $completedTasks->count() + 1;
        $tasks = $validation['tasks'];
        foreach ($tasks as $index => &$task) {
            $task['order'] = $startingOrder + $index;
        }
        unset($task);

        // 11. Assign dates to new tasks
        $tasksWithDates = $this->scheduling->assignDates($tasks, $capacity['slots']);

        // 12. Execute database changes in a transaction
        $deletedCount = 0;

        DB::transaction(function () use ($goal, $tasksWithDates, $newDescription, $newDeadline, $newAvailabilityRules, &$deletedCount) {
            // Delete all non-completed tasks (scheduled + skipped)
            $deletedCount = $goal->tasks()
                ->whereIn('status', [Task::STATUS_SCHEDULED, Task::STATUS_SKIPPED])
                ->delete();

            // Create new tasks
            foreach ($tasksWithDates as $taskData) {
                $goal->tasks()->create([
                    'title' => $taskData['title'],
                    'description' => $taskData['description'] ?? null,
                    'order' => $taskData['order'],
                    'scheduled_at' => $taskData['scheduled_at'],
                    'status' => Task::STATUS_SCHEDULED,
                ]);
            }

            // Persist goal field changes (deadline, availability, description)
            $goalUpdates = [];

            if ($newDescription !== null) {
                $goalUpdates['description'] = $newDescription;
            }
            if ($newDeadline !== null) {
                $goalUpdates['deadline'] = $newDeadline;
            }
            if ($newAvailabilityRules !== null) {
                $goalUpdates['availability_rules'] = $newAvailabilityRules;
            }

            if (!empty($goalUpdates)) {
                $goal->update($goalUpdates);
            }

            // Reactivate if completed
            if ($goal->isCompleted()) {
                $goal->reactivate();
            }

            // Recalculate total sessions/hours (completed + new)
            $totalTasks = $goal->tasks()->count();
            $goal->calculateCapacity($totalTasks);
        });

        Log::info('ReplanService: replan completed', [
            'goal_id' => $goal->id,
            'deleted_tasks' => $deletedCount,
            'new_tasks' => count($tasksWithDates),
        ]);

        return [
            'success' => true,
            'tasks' => $tasksWithDates,
            'error' => null,
            'warning' => $validation['warning'],
            'replan_stats' => [
                'completed_kept' => $completedTasks->count(),
                'deleted' => $deletedCount,
                'generated' => count($tasksWithDates),
            ],
        ];
    }

    /**
     * Build the system prompt for replan.
     *
     * Differentiates from PlannerService's prompt by instructing
     * the LLM to continue from previous progress.
     */
    private function buildSystemPrompt(int $totalNewTasks, int $completedCount): string
    {
        $contextInstruction = $completedCount > 0
            ? "El usuario ya completó {$completedCount} tareas previas. Debes generar tareas que CONTINÚEN desde donde dejó, sin repetir temas ya cubiertos."
            : 'El usuario aún no ha completado ninguna tarea. Genera un plan desde el inicio.';

        return <<<PROMPT
Eres un experto en planificación educativa y productividad. Tu trabajo es crear planes de estudio detallados y accionables.

CONTEXTO DE REPLANIFICACIÓN:
{$contextInstruction}

REGLAS ESTRICTAS:

1. Responde SOLO con JSON válido, sin texto adicional
2. Genera EXACTAMENTE {$totalNewTasks} tareas NUEVAS
3. Cada tarea debe poder completarse en UNA sesión
4. Las tareas deben estar en orden lógico de aprendizaje
5. Los títulos deben ser claros y accionables (verbos de acción)
6. Las descripciones deben explicar qué hacer y qué lograr
7. NO repitas temas que el usuario ya completó

FORMATO DE RESPUESTA:
{
  "tasks": [
    {
      "order": 1,
      "title": "Título claro y conciso",
      "description": "Descripción de qué hacer y objetivo"
    }
  ]
}

IMPORTANTE: El array "tasks" debe tener exactamente {$totalNewTasks} elementos.
PROMPT;
    }

    /**
     * Build the user prompt with goal context and completion history.
     */
    private function buildUserPrompt(Goal $goal, array $capacity, $completedTasks, ?string $newDescription): string
    {
        $currentDate = Carbon::now()->format('Y-m-d');
        $daysUntilDeadline = Carbon::now()->diffInDays($goal->deadline, false);

        // Use new description if provided, otherwise use existing
        $descriptionText = $newDescription ?? $goal->description;
        $descriptionLine = $descriptionText ? "\nDESCRIPCIÓN ADICIONAL: {$descriptionText}" : '';

        // Build completed tasks context
        $completedContext = '';
        if ($completedTasks->isNotEmpty()) {
            $completedLines = [];
            foreach ($completedTasks as $index => $task) {
                $num = $index + 1;
                $desc = $task->description ? " — {$task->description}" : '';
                $completedLines[] = "{$num}. {$task->title}{$desc}";
            }
            $completedContext = "\n\nTAREAS YA COMPLETADAS (NO repetir estos temas):\n" . implode("\n", $completedLines);
        }

        return <<<PROMPT
Genera un plan de estudio CONTINUANDO el progreso del siguiente objetivo:

OBJETIVO: {$goal->title}{$descriptionLine}

CONTEXTO:
- Fecha actual: {$currentDate}
- Fecha límite: {$goal->deadline->format('Y-m-d')}
- Días disponibles: {$daysUntilDeadline}
- Duración de cada sesión: {$goal->session_duration_minutes} minutos
- Total de sesiones disponibles: {$capacity['total_sessions']}
- Total de horas: {$capacity['total_hours']}{$completedContext}

INSTRUCCIONES:
Genera exactamente {$capacity['total_sessions']} tareas NUEVAS.
Cada tarea será realizada en una sesión de {$goal->session_duration_minutes} minutos.
Ordénalas de forma progresiva, continuando desde el nivel alcanzado en las tareas completadas.

Responde en español.
PROMPT;
    }

    /**
     * Build a standardized error result.
     */
    private function errorResult(string $error, ?string $code = null): array
    {
        return [
            'success' => false,
            'tasks' => [],
            'error' => $error,
            'error_code' => $code,
            'warning' => null,
            'replan_stats' => null,
        ];
    }
}
