<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Not found error response schema (HTTP 404).
 *
 * Returned when a requested resource does not exist.
 */
#[OA\Schema(
    schema: 'NotFoundError',
    title: 'NotFoundError',
    description: 'Recurso no encontrado (404)',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'No query results for model.'),
    ]
)]
class NotFoundError
{
}
