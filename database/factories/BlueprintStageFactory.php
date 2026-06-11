<?php

namespace Database\Factories;

use App\Modules\Blueprint\Enums\AssignmentCardinality;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\StageType;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlueprintStageFactory extends Factory
{
    protected $model = BlueprintStage::class;

    public function definition(): array
    {
        return [
            'blueprint_id' => Blueprint::factory(),
            'stage_type_id' => StageType::factory(),
            'sla_policy_id' => null,
            'name_ar' => $this->faker->word(),
            'name_en' => $this->faker->word(),
            'description_ar' => $this->faker->sentence(),
            'description_en' => $this->faker->sentence(),
            'sequence_order' => $this->faker->numberBetween(1, 50),
            'assignment_type' => AssignmentType::ManualAtLaunch,
            'assigned_position_id' => null,
            'assigned_department_id' => null,
            'assignment_cardinality' => AssignmentCardinality::Single,
            'completion_rule' => CompletionRule::AnyAssignee,
            'escalation_position_id' => null,
        ];
    }
}
