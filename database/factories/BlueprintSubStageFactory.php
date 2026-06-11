<?php

namespace Database\Factories;

use App\Modules\Blueprint\Enums\AssignmentCardinality;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlueprintSubStageFactory extends Factory
{
    protected $model = BlueprintSubStage::class;

    public function definition(): array
    {
        return [
            'blueprint_stage_id' => BlueprintStage::factory(),
            'sla_policy_id' => null,
            'name_ar' => $this->faker->word(),
            'name_en' => $this->faker->word(),
            'description_ar' => $this->faker->sentence(),
            'description_en' => $this->faker->sentence(),
            'sequence_order' => $this->faker->numberBetween(1, 20),
            'is_required' => true,
            'assignment_type' => AssignmentType::ManualAtLaunch,
            'assigned_position_id' => null,
            'assigned_department_id' => null,
            'assignment_cardinality' => AssignmentCardinality::Single,
            'completion_rule' => CompletionRule::AnyAssignee,
        ];
    }
}
