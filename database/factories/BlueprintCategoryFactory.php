<?php

namespace Database\Factories;

use App\Modules\Blueprint\Models\BlueprintCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlueprintCategoryFactory extends Factory
{
    protected $model = BlueprintCategory::class;

    public function definition(): array
    {
        return [
            'name_ar' => $this->faker->word(),
            'name_en' => $this->faker->word(),
            'display_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
