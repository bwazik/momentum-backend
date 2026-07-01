<?php

namespace Database\Factories;

use App\Modules\Task\Enums\ExternalReferenceType;
use App\Modules\Task\Models\ExternalEntity;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskExternalReference;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskExternalReferenceFactory extends Factory
{
    protected $model = TaskExternalReference::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'reference_type' => fake()->randomElement(ExternalReferenceType::cases()),
            'reference_number' => fake()->unique()->ean8(),
            'external_entity_id' => ExternalEntity::factory(),
            'notes' => fake()->sentence(),
        ];
    }
}
