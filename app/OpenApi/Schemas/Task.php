<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Task resource schema.
 *
 * Represents a scheduled study session belonging to a goal.
 */
#[OA\Schema(
    schema: 'Task',
    title: 'Task',
    description: 'Modelo de tarea programada',
    required: ['id', 'goal_id', 'title', 'order', 'scheduled_at', 'end_at', 'duration_minutes', 'status', 'is_locked', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'goal_id', type: 'integer', example: 1),
        new OA\Property(property: 'title', type: 'string', example: 'Introducción a Laravel'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Instalar Laravel y crear tu primer proyecto'),
        new OA\Property(property: 'order', type: 'integer', example: 1),
        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', example: '2026-02-10T09:00:00.000000Z'),
        new OA\Property(property: 'end_at', type: 'string', format: 'date-time', example: '2026-02-10T10:00:00.000000Z'),
        new OA\Property(property: 'duration_minutes', type: 'integer', example: 60),
        new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'completed', 'skipped'], example: 'scheduled'),
        new OA\Property(property: 'is_locked', type: 'boolean', example: false),
        new OA\Property(property: 'notes', type: 'string', nullable: true, example: null),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true, example: null),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-02-05T10:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-02-05T10:00:00.000000Z'),
    ]
)]
class Task
{
}
