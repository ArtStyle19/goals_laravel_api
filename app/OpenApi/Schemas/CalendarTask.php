<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Task formatted for calendar views.
 *
 * Simplified task representation that includes the parent goal's
 * title, used by the calendar endpoints.
 */
#[OA\Schema(
    schema: 'CalendarTask',
    title: 'CalendarTask',
    description: 'Tarea formateada para vista de calendario',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'goal_id', type: 'integer', example: 1),
        new OA\Property(property: 'goal_title', type: 'string', example: 'Aprender Laravel'),
        new OA\Property(property: 'title', type: 'string', example: 'Introducción a Laravel'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Instalar Laravel y crear tu primer proyecto'),
        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', example: '2026-02-10T09:00:00+00:00'),
        new OA\Property(property: 'end_at', type: 'string', format: 'date-time', example: '2026-02-10T10:00:00+00:00'),
        new OA\Property(property: 'duration_minutes', type: 'integer', example: 60),
        new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'completed', 'skipped'], example: 'scheduled'),
        new OA\Property(property: 'is_locked', type: 'boolean', example: false),
    ]
)]
class CalendarTask
{
}
