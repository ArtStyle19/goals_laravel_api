<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Goal progress statistics schema.
 *
 * Aggregated metrics about a goal's progress including completion
 * percentages, hours tracked, and whether the user is on schedule.
 */
#[OA\Schema(
    schema: 'GoalStats',
    title: 'GoalStats',
    description: 'Estadísticas de progreso de un objetivo',
    properties: [
        new OA\Property(property: 'total_tasks', type: 'integer', example: 20),
        new OA\Property(property: 'completed_tasks', type: 'integer', example: 8),
        new OA\Property(property: 'skipped_tasks', type: 'integer', example: 1),
        new OA\Property(property: 'pending_tasks', type: 'integer', example: 11),
        new OA\Property(property: 'progress_percentage', type: 'number', format: 'float', example: 40.0),
        new OA\Property(property: 'total_hours_planned', type: 'string', example: '20.0'),
        new OA\Property(property: 'hours_completed', type: 'number', format: 'float', example: 8.0),
        new OA\Property(property: 'hours_remaining', type: 'number', format: 'float', example: 11.0),
        new OA\Property(property: 'days_until_deadline', type: 'integer', example: 45),
        new OA\Property(property: 'on_track', type: 'boolean', example: true),
    ]
)]
class GoalStats
{
}
