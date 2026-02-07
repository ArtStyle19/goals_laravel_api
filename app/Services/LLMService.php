<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LLMService - Servicio de integración con OpenAI
 * 
 * Responsabilidad única: Comunicación con la API de OpenAI.
 * No contiene lógica de negocio específica del dominio.
 */
class LLMService
{
    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxTokens;
    private float $temperature;

    public function __construct()
    {
        // Configuración centralizada desde config/services.php
        $this->apiKey = (string) config('services.openai.api_key', '');
        $this->model = (string) config('services.openai.model', 'gpt-4o-mini');
        $this->timeout = (int) config('services.openai.timeout', 60);
        $this->maxTokens = (int) config('services.openai.max_tokens', 4000);
        $this->temperature = (float) config('services.openai.temperature', 0.7);
    }

    /**
     * Call the LLM with system and user prompts.
     *
     * @param string $systemPrompt The system instructions
     * @param string $userPrompt The user message/request
     * @return string|null The LLM response content, or null on failure
     */
    public function call(string $systemPrompt, string $userPrompt): ?string
    {
        if (!$this->isConfigured()) {
            Log::error('LLMService: OPENAI_API_KEY is not configured');
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => $this->temperature,
                    'max_tokens' => $this->maxTokens,
                ]);

            if (!$response->successful()) {
                Log::error('LLMService: API call failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            
            return $data['choices'][0]['message']['content'] ?? null;
        } catch (\Exception $e) {
            Log::error('LLMService: Exception during API call', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract JSON from the LLM response.
     * Handles markdown code blocks and cleans the response.
     *
     * @param string $response The raw LLM response
     * @return array|null Parsed JSON as array, or null on failure
     */
    public function extractJson(string $response): ?array
    {
        // First, try to parse as-is
        $decoded = json_decode($response, true);
        if ($decoded !== null) {
            return $decoded;
        }

        // Try to extract from markdown code block
        $patterns = [
            '/```json\s*([\s\S]*?)\s*```/',  // ```json ... ```
            '/```\s*([\s\S]*?)\s*```/',       // ``` ... ```
            '/\{[\s\S]*\}/',                   // Raw JSON object
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $response, $matches)) {
                $jsonString = $matches[1] ?? $matches[0];
                $decoded = json_decode($jsonString, true);
                if ($decoded !== null) {
                    return $decoded;
                }
            }
        }

        Log::warning('LLMService: Could not extract JSON from response', [
            'response' => substr($response, 0, 500),
        ]);

        return null;
    }

    /**
     * Validate tasks structure from LLM response.
     * Applies tolerance for task count and normalizes data.
     *
     * @param array $tasks Array of tasks from LLM
     * @param int $expectedCount Expected number of tasks
     * @return array Validation result with 'valid', 'tasks', 'error', 'warning'
     */
    public function validateTasks(array $tasks, int $expectedCount): array
    {
        // Must have tasks
        if (empty($tasks)) {
            return [
                'valid' => false,
                'tasks' => [],
                'error' => 'No se recibieron tareas del LLM',
                'warning' => null,
            ];
        }

        // Filter tasks that have at least a title
        $validTasks = [];
        foreach ($tasks as $task) {
            if (!isset($task['title']) || empty(trim($task['title']))) {
                continue;
            }

            $validTasks[] = [
                'title' => mb_substr(trim($task['title']), 0, 255),
                'description' => isset($task['description']) ? trim($task['description']) : null,
            ];
        }

        if (empty($validTasks)) {
            return [
                'valid' => false,
                'tasks' => [],
                'error' => 'Ninguna tarea válida en la respuesta del LLM',
                'warning' => null,
            ];
        }

        // Assign sequential order
        foreach ($validTasks as $index => &$task) {
            $task['order'] = $index + 1;
        }

        // Check tolerance (±20%)
        $minTasks = (int) floor($expectedCount * 0.8);
        $maxTasks = (int) ceil($expectedCount * 1.2);
        $actualCount = count($validTasks);

        $warning = null;
        if ($actualCount < $minTasks || $actualCount > $maxTasks) {
            $warning = "Se esperaban {$expectedCount} tareas pero se recibieron {$actualCount}";
        }

        // If we have too many tasks, truncate to expected
        if ($actualCount > $expectedCount) {
            $validTasks = array_slice($validTasks, 0, $expectedCount);
            // Re-assign order after truncation
            foreach ($validTasks as $index => &$task) {
                $task['order'] = $index + 1;
            }
        }

        return [
            'valid' => true,
            'tasks' => $validTasks,
            'error' => null,
            'warning' => $warning,
        ];
    }

    /**
     * Check if the service is configured and ready to use.
     *
     * @return bool True if API key is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}
