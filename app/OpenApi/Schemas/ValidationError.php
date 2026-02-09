<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Validation error response schema (HTTP 422).
 *
 * Standard Laravel validation error format with a message
 * and a keyed object of field-level error arrays.
 */
#[OA\Schema(
    schema: 'ValidationError',
    title: 'ValidationError',
    description: 'Error de validación (422)',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            description: 'Objeto con los campos y sus mensajes de error',
            example: '{"email": ["The email field is required."]}'
        ),
    ]
)]
class ValidationError
{
}
