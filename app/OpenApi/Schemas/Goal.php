<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Goal resource schema.
 *
 * Represents a user's learning goal with its configuration
 * (availability rules, session duration, deadline).
 */
#[OA\Schema(
    schema: 'Goal',
    title: 'Goal',
    description: 'Modelo de objetivo/meta',
    required: ['id', 'user_id', 'title', 'deadline', 'session_duration_minutes', 'availability_rules', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'title', type: 'string', example: 'Aprender Laravel'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Curso completo de Laravel desde cero'),
        new OA\Property(property: 'deadline', type: 'string', format: 'date', example: '2026-06-01'),
        new OA\Property(property: 'session_duration_minutes', type: 'integer', enum: [30, 45, 60, 90, 120], example: 60),
        new OA\Property(
            property: 'availability_rules',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AvailabilityRule')
        ),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'completed'], example: 'draft'),
        new OA\Property(property: 'total_sessions', type: 'integer', nullable: true, example: 24),
        new OA\Property(property: 'total_hours', type: 'string', nullable: true, example: '24.0', description: 'Total de horas planificadas'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true, example: null),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-02-05T10:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-02-05T10:00:00.000000Z'),
    ]
)]
class Goal
{
}
