<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Task\Enums\AssignmentRole;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskStageAssignmentFactory extends Factory
{
    protected $model = TaskStageAssignment::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'stage_instance_id' => TaskStageInstance::factory(),
            'sub_stage_instance_id' => null,
            'user_id' => User::factory(),
            'position_id' => null,
            'delegated_from_user_id' => null,
            'assignment_role' => AssignmentRole::Required,
            'is_completed' => false,
            'assigned_at' => now(),
        ];
    }
}
