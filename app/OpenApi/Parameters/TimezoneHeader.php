<?php

namespace App\OpenApi\Parameters;

use OpenApi\Attributes as OA;

/**
 * Reusable X-Timezone header parameter.
 *
 * Used by endpoints that need the client's IANA timezone to resolve
 * date boundaries (e.g. "today") or schedule tasks correctly.
 *
 * @see https://swagger.io/specification/#parameter-object
 */
#[OA\Parameter(
    parameter: 'X-Timezone',
    name: 'X-Timezone',
    in: 'header',
    required: false,
    description: 'Zona horaria IANA del cliente (ej: America/Mexico_City). Por defecto: UTC',
    schema: new OA\Schema(type: 'string', example: 'America/Mexico_City')
)]
class TimezoneHeader
{
}
