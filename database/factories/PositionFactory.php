<?php

namespace Database\Factories;

use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'title_ar' => fake()->jobTitle(),
            'title_en' => fake()->jobTitle(),
            'authority_grade_id' => AuthorityGrade::factory(),
            'is_department_head' => false,
            'is_active' => true,
        ];
    }

    public function departmentHead(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_department_head' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
