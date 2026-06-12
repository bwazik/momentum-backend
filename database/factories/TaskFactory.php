<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'blueprint_id' => Blueprint::factory(),
            'priority_id' => TaskPriority::factory(),
            'title_ar' => fake()->sentence(),
            'title_en' => fake()->sentence(),
            'description_ar' => fake()->paragraph(),
            'description_en' => fake()->paragraph(),
            'classification_level' => ClassificationLevel::Public,
            'initiator_user_id' => User::factory(),
            'status' => TaskStatus::Draft,
            'due_date' => fake()->optional()->dateTimeBetween('now', '+30 days'),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Draft]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Active,
            'launched_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Suspended,
            'launched_at' => now()->subHour(),
            'suspended_at' => now(),
            'suspension_reason' => 'Test suspension',
        ]);
    }
}
