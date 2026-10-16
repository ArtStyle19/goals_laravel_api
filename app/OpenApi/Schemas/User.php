<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * User resource schema.
 *
 * Represents the public-facing structure of a User model
 * as returned by the API (excludes sensitive fields like password).
 */
#[OA\Schema(
    schema: 'User',
    title: 'User',
    description: 'Modelo de usuario',
    required: ['id', 'name', 'email', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Juan Pérez'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'juan@example.com'),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true, example: null),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-02-05T10:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-02-05T10:00:00.000000Z'),
    ]
)]
class User
{
}
