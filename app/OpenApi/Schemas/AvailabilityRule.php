<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Weekly availability rule schema.
 *
 * Describes a single time block on a specific day of the week
 * during which the user is available for study sessions.
 */
#[OA\Schema(
    schema: 'AvailabilityRule',
    title: 'AvailabilityRule',
    description: 'Regla de disponibilidad semanal',
    required: ['day', 'start', 'end'],
    properties: [
        new OA\Property(
            property: 'day',
            type: 'string',
            enum: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            example: 'monday'
        ),
        new OA\Property(property: 'start', type: 'string', format: 'time', example: '09:00', description: 'Hora de inicio (HH:mm)'),
        new OA\Property(property: 'end', type: 'string', format: 'time', example: '12:00', description: 'Hora de fin (HH:mm)'),
    ]
)]
class AvailabilityRule
{
}
