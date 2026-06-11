<?php

namespace Database\Factories;

use App\Modules\Blueprint\Enums\SlaUnit;
use App\Modules\Blueprint\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

class SlaPolicyFactory extends Factory
{
    protected $model = SlaPolicy::class;

    public function definition(): array
    {
        return [
            'name_ar' => $this->faker->word(),
            'name_en' => $this->faker->word(),
            'sla_value' => $this->faker->numberBetween(1, 30),
            'sla_unit' => SlaUnit::Days,
            'warning_threshold_percentage' => 75,
            'is_active' => true,
        ];
    }
}
