<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Forbidden error response schema (HTTP 403).
 *
 * Returned when the authenticated user does not own the
 * requested resource.
 */
#[OA\Schema(
    schema: 'ForbiddenError',
    title: 'ForbiddenError',
    description: 'Error de autorización (403)',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'No autorizado'),
    ]
)]
class ForbiddenError
{
}
