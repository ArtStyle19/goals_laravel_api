<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Task detail schema with parent goal info.
 *
 * Extended task representation that includes the parent goal's
 * title, used by task show/list endpoints.
 */
#[OA\Schema(
    schema: 'TaskDetail',
    title: 'TaskDetail',
    description: 'Tarea con información del objetivo padre',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'goal_id', type: 'integer', example: 1),
        new OA\Property(property: 'goal_title', type: 'string', example: 'Aprender Laravel'),
        new OA\Property(property: 'title', type: 'string', example: 'Introducción a Laravel'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Instalar Laravel'),
        new OA\Property(property: 'order', type: 'integer', example: 1),
        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', example: '2026-02-10T09:00:00.000000Z'),
        new OA\Property(property: 'end_at', type: 'string', format: 'date-time', example: '2026-02-10T10:00:00.000000Z'),
        new OA\Property(property: 'duration_minutes', type: 'integer', example: 60),
        new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'completed', 'skipped'], example: 'scheduled'),
        new OA\Property(property: 'is_locked', type: 'boolean', example: false),
        new OA\Property(property: 'notes', type: 'string', nullable: true, example: null),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true, example: null),
    ]
)]
class TaskDetail
{
}
