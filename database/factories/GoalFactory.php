<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'deadline' => now()->addWeeks(4)->toDateString(),
            'session_duration_minutes' => fake()->randomElement([30, 45, 60, 90, 120]),
            'availability_rules' => [
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ['day' => 'wednesday', 'start' => '14:00', 'end' => '17:00'],
                ['day' => 'friday', 'start' => '09:00', 'end' => '12:00'],
            ],
            'status' => 'draft',
            'total_sessions' => null,
            'total_hours' => null,
        ];
    }

    /**
     * Goal with 60-minute sessions and simple availability.
     */
    public function withSimpleAvailability(): static
    {
        return $this->state(fn (array $attributes) => [
            'session_duration_minutes' => 60,
            'availability_rules' => [
                ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'],
                ['day' => 'wednesday', 'start' => '09:00', 'end' => '12:00'],
            ],
        ]);
    }

    /**
     * Active goal.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Completed goal.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
