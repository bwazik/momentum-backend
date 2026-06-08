<?php

namespace Database\Factories;

use App\Modules\Organization\Models\PublicHoliday;
use App\Modules\Organization\Models\WorkingCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicHoliday>
 */
class PublicHolidayFactory extends Factory
{
    protected $model = PublicHoliday::class;

    public function definition(): array
    {
        return [
            'working_calendar_id' => WorkingCalendar::factory(),
            'name_ar' => fake()->word(),
            'name_en' => fake()->word(),
            'holiday_date' => fake()->date(),
            'is_recurring' => false,
        ];
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recurring' => true,
        ]);
    }
}
