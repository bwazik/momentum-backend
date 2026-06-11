<?php

namespace Database\Factories;

use App\Modules\Blueprint\Enums\BlueprintScope;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlueprintFactory extends Factory
{
    protected $model = Blueprint::class;

    public function definition(): array
    {
        return [
            'category_id' => BlueprintCategory::factory(),
            'name_ar' => $this->faker->word(),
            'name_en' => $this->faker->word(),
            'description_ar' => $this->faker->sentence(),
            'description_en' => $this->faker->sentence(),
            'scope' => BlueprintScope::Organization,
            'department_id' => null,
            'is_locked' => false,
            'is_active' => true,
            'created_by_user_id' => 1,
        ];
    }
}
