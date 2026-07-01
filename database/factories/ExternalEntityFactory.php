<?php

namespace Database\Factories;

use App\Modules\Task\Enums\ExternalEntityType;
use App\Modules\Task\Models\ExternalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExternalEntityFactory extends Factory
{
    protected $model = ExternalEntity::class;

    public function definition(): array
    {
        return [
            'name_ar' => fake()->company(),
            'name_en' => fake()->company(),
            'entity_type' => fake()->randomElement(ExternalEntityType::cases()),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
