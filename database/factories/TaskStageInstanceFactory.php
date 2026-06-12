<?php

namespace Database\Factories;

use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskStageInstanceFactory extends Factory
{
    protected $model = TaskStageInstance::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'blueprint_stage_id' => BlueprintStage::factory(),
            'sequence_order' => fake()->numberBetween(1, 100),
            'completion_rule' => 1,
            'status' => StageInstanceStatus::Active,
            'entered_at' => now(),
        ];
    }
}
