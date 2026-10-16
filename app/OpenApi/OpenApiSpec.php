<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * OpenAPI specification metadata.
 *
 * This class serves as the single entry point for all top-level OpenAPI
 * annotations: general info, server configuration, security schemes,
 * and tag definitions.
 *
 * @see https://swagger.io/specification/#info-object
 */
#[OA\Info(
    version: '1.0.0',
    title: 'Goals Planner API',
    description: 'API REST para la aplicación móvil Goals Planner. Permite gestionar objetivos personales, generar planes de estudio con IA, programar tareas en calendario y hacer seguimiento del progreso.',
    contact: new OA\Contact(
        name: 'Goals Planner Team',
        email: 'support@goalsplanner.app'
    ),
)]
#[OA\Server(
    url: '/api',
    description: 'API Server'
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'plaintext',
    description: 'Ingresa tu token de Sanctum. Obtén uno via POST /api/register o POST /api/login'
)]
#[OA\Tag(name: 'Auth', description: 'Autenticación y gestión de sesión')]
#[OA\Tag(name: 'Goals', description: 'CRUD y acciones sobre objetivos')]
#[OA\Tag(name: 'Tasks', description: 'Gestión de tareas individuales')]
#[OA\Tag(name: 'Calendar', description: 'Vistas de calendario y tareas del día')]
class OpenApiSpec
{
}
