<?php

namespace Database\Factories;

use App\Modules\Blueprint\Enums\TransitionType;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintTransition;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlueprintTransitionFactory extends Factory
{
    protected $model = BlueprintTransition::class;

    public function definition(): array
    {
        return [
            'blueprint_id' => Blueprint::factory(),
            'from_stage_id' => BlueprintStage::factory(),
            'to_stage_id' => BlueprintStage::factory(),
            'transition_type' => TransitionType::Advance,
            'return_reason_required' => false,
        ];
    }
}
