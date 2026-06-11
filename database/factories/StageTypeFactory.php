<?php

namespace Database\Factories;

use App\Modules\Blueprint\Models\StageType;
use Illuminate\Database\Eloquent\Factories\Factory;

class StageTypeFactory extends Factory
{
    protected $model = StageType::class;

    public function definition(): array
    {
        return [
            'name_ar' => $this->faker->word(),
            'name_en' => $this->faker->word(),
            'is_system_default' => false,
            'is_active' => true,
            'display_order' => $this->faker->numberBetween(0, 100),
        ];
    }
}
