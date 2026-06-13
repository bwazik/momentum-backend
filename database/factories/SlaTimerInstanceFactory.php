<?php

namespace Database\Factories;

use App\Modules\Tracking\Enums\SlaTimerStatus;
use App\Modules\Tracking\Models\SlaTimerInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class SlaTimerInstanceFactory extends Factory
{
    protected $model = SlaTimerInstance::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) str()->uuid7(),
            'status' => SlaTimerStatus::Running,
            'started_at' => now(),
            'deadline_at' => now()->addDays(5),
            'elapsed_before_pause' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => SlaTimerStatus::Running,
            'deadline_at' => now()->addDays(5),
            'warning_at' => now()->addDays(2),
            'paused_at' => null,
            'completed_at' => null,
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => SlaTimerStatus::Warning,
            'warning_at' => now()->subMinute(),
            'deadline_at' => now()->addHour(),
        ]);
    }

    public function breached(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => SlaTimerStatus::Breached,
            'deadline_at' => now()->subMinute(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => SlaTimerStatus::Paused,
            'deadline_at' => now()->addDays(5),
            'paused_at' => now(),
            'elapsed_before_pause' => 3600,
        ]);
    }
}
