<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Goal with nested tasks schema.
 *
 * Extends the base Goal with the full list of associated tasks,
 * typically returned by the goal detail endpoint.
 */
#[OA\Schema(
    schema: 'GoalWithTasks',
    title: 'GoalWithTasks',
    description: 'Goal con lista completa de tareas',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Goal'),
        new OA\Schema(
            properties: [
                new OA\Property(
                    property: 'tasks',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Task')
                ),
            ]
        ),
    ]
)]
class GoalWithTasks
{
}
