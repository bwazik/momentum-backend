<?php

namespace Database\Factories;

use App\Modules\Organization\Models\WorkingCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingCalendar>
 */
class WorkingCalendarFactory extends Factory
{
    protected $model = WorkingCalendar::class;

    public function definition(): array
    {
        return [
            'name_ar' => fake()->unique()->word(),
            'name_en' => fake()->unique()->word(),
            'working_days' => '0,1,2,3,4',
            'working_hours_start' => '08:00',
            'working_hours_end' => '16:00',
            'timezone' => 'Asia/Riyadh',
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
