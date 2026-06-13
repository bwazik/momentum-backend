<?php

namespace Database\Factories;

use App\Modules\Tracking\Enums\EscalationStatus;
use App\Modules\Tracking\Enums\EscalationType;
use App\Modules\Tracking\Models\Escalation;
use Illuminate\Database\Eloquent\Factories\Factory;

class EscalationFactory extends Factory
{
    protected $model = Escalation::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) str()->uuid7(),
            'escalation_type' => EscalationType::Manual,
            'status' => EscalationStatus::Open,
            'reason' => fake()->sentence(),
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => EscalationStatus::Open,
            'resolved_at' => null,
            'resolution_note' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => EscalationStatus::Resolved,
            'resolution_note' => fake()->sentence(),
            'resolved_at' => now(),
        ]);
    }

    public function autoBreach(): static
    {
        return $this->state(fn (array $attrs) => [
            'escalation_type' => EscalationType::AutoSlaBreach,
        ]);
    }
}
