<?php

namespace App\Services;

use App\Models\Goal;
use Carbon\Carbon;

class PlannerService
{
    public function __construct(
        private LLMService $llm,
        private SchedulingService $scheduling
    ) {}

    /**
     * Generate tasks for a goal using LLM and assign dates.
     *
     * @param Goal $goal The goal to generate tasks for
     * @param string $timezone IANA timezone of the user (e.g. "America/Mexico_City")
     * @return array Result with 'success', 'tasks', 'error', 'warning'
     */
    public function generatePlan(Goal $goal, string $timezone = 'UTC'): array
    {
        // Check if LLM is configured
        if (!$this->llm->isConfigured()) {
            return [
                'success' => false,
                'tasks' => [],
                'error' => 'El servicio de LLM no está configurado. Configura OPENAI_API_KEY.',
                'warning' => null,
            ];
        }

        // 1. Calculate capacity
        $capacity = $this->scheduling->calculateCapacity($goal, null, $timezone);

        if ($capacity['total_sessions'] === 0) {
            return [
                'success' => false,
                'tasks' => [],
                'error' => 'No hay slots disponibles. Verifica la disponibilidad y el deadline.',
                'warning' => null,
            ];
        }

        // 2. Build prompts
        $systemPrompt = $this->getSystemPrompt($capacity['total_sessions']);
        $userPrompt = $this->buildUserPrompt($goal, $capacity);

        // 3. Call LLM
        $response = $this->llm->call($systemPrompt, $userPrompt);

        if ($response === null) {
            return [
                'success' => false,
                'tasks' => [],
                'error' => 'Error al comunicarse con el LLM. Intenta de nuevo.',
                'warning' => null,
            ];
        }

        // 4. Extract JSON
        $data = $this->llm->extractJson($response);

        if ($data === null || !isset($data['tasks'])) {
            return [
                'success' => false,
                'tasks' => [],
                'error' => 'El LLM no devolvió un formato válido. Intenta de nuevo.',
                'warning' => null,
            ];
        }

        // 5. Validate tasks
        $validation = $this->llm->validateTasks(
            $data['tasks'],
            $capacity['total_sessions']
        );

        if (!$validation['valid']) {
            return [
                'success' => false,
                'tasks' => [],
                'error' => $validation['error'],
                'warning' => null,
            ];
        }

        // 6. Assign dates
        $tasksWithDates = $this->scheduling->assignDates(
            $validation['tasks'],
            $capacity['slots']
        );

        return [
            'success' => true,
            'tasks' => $tasksWithDates,
            'error' => null,
            'warning' => $validation['warning'],
        ];
    }

    /**
     * Get the system prompt for the LLM.
     *
     * @param int $totalTasks Number of tasks to generate
     * @return string The system prompt
     */
    private function getSystemPrompt(int $totalTasks): string
    {
        return <<<PROMPT
Eres un experto en planificación educativa y productividad. Tu trabajo es crear planes de estudio detallados y accionables.

REGLAS ESTRICTAS:

1. Responde SOLO con JSON válido, sin texto adicional
2. Genera EXACTAMENTE {$totalTasks} tareas
3. Cada tarea debe poder completarse en UNA sesión
4. Las tareas deben estar en orden lógico de aprendizaje
5. Los títulos deben ser claros y accionables (verbos de acción)
6. Las descripciones deben explicar qué hacer y qué lograr

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

IMPORTANTE: El array "tasks" debe tener exactamente {$totalTasks} elementos.
PROMPT;
    }

    /**
     * Build the user prompt with goal context.
     *
     * @param Goal $goal The goal
     * @param array $capacity Capacity information
     * @return string The user prompt
     */
    private function buildUserPrompt(Goal $goal, array $capacity): string
    {
        $currentDate = Carbon::now()->format('Y-m-d');
        $daysUntilDeadline = Carbon::now()->diffInDays($goal->deadline, false);

        $description = $goal->description ? "\nDESCRIPCIÓN ADICIONAL: {$goal->description}" : '';

        return <<<PROMPT
Genera un plan de estudio para el siguiente objetivo:

OBJETIVO: {$goal->title}{$description}

CONTEXTO:
- Fecha actual: {$currentDate}
- Fecha límite: {$goal->deadline}
- Días disponibles: {$daysUntilDeadline}
- Duración de cada sesión: {$goal->session_duration_minutes} minutos
- Total de sesiones disponibles: {$capacity['total_sessions']}
- Total de horas: {$capacity['total_hours']}

INSTRUCCIONES:
Genera exactamente {$capacity['total_sessions']} tareas.
Cada tarea será realizada en una sesión de {$goal->session_duration_minutes} minutos.
Ordénalas de forma progresiva (de básico a avanzado).

Responde en español.
PROMPT;
    }
}
