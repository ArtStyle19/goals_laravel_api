<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Goal with task counts schema (used in listings).
 *
 * Extends the base Goal with aggregated task counts, typically
 * returned by the goals index endpoint.
 */
#[OA\Schema(
    schema: 'GoalWithCounts',
    title: 'GoalWithCounts',
    description: 'Goal con conteos de tareas (usado en listados)',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Goal'),
        new OA\Schema(
            properties: [
                new OA\Property(property: 'tasks_count', type: 'integer', example: 10, description: 'Total de tareas'),
                new OA\Property(property: 'completed_tasks_count', type: 'integer', example: 3, description: 'Tareas completadas'),
            ]
        ),
    ]
)]
class GoalWithCounts
{
}
