<?php

namespace Database\Factories;

use App\Modules\Task\Models\TaskPriority;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskPriorityFactory extends Factory
{
    protected $model = TaskPriority::class;

    public function definition(): array
    {
        return [
            'name_ar' => fake()->word(),
            'name_en' => fake()->word(),
            'severity_rank' => fake()->unique()->numberBetween(1, 100),
            'color_code' => fake()->hexColor(),
            'is_default' => false,
            'is_active' => true,
            'display_order' => fake()->unique()->numberBetween(1, 100),
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
