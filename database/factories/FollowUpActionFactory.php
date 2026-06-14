<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\FollowUp\Enums\FollowUpActionType;
use App\Modules\FollowUp\Models\FollowUpAction;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class FollowUpActionFactory extends Factory
{
    protected $model = FollowUpAction::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'action_type' => fake()->randomElement(FollowUpActionType::cases()),
            'note_ar' => fake()->sentence(),
            'note_en' => fake()->optional()->sentence(),
            'contact_name' => fake()->optional()->name(),
        ];
    }
}
