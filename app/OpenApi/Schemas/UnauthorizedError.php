<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Unauthorized error response schema (HTTP 401).
 *
 * Returned when a request lacks valid authentication credentials.
 */
#[OA\Schema(
    schema: 'UnauthorizedError',
    title: 'UnauthorizedError',
    description: 'Error de autenticación (401)',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
    ]
)]
class UnauthorizedError
{
}
