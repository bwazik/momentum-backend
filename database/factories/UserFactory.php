<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'public_id' => null,
            'name_ar' => fake()->name(),
            'name_en' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'mobile' => fake()->optional()->phoneNumber(),
            'employee_id' => fake()->optional(0.7)->numerify('EMP#####'),
            'account_type' => AccountType::INTERNAL_USER,
            'preferred_language' => 1,
            'is_active' => true,
            'is_out_of_office' => false,
            'email_verified_at' => now(),
        ];
    }

    public function tenantAdmin(): static
    {
        return $this->state(['account_type' => AccountType::TENANT_ADMIN]);
    }

    public function externalAuditor(): static
    {
        return $this->state(['account_type' => AccountType::EXTERNAL_AUDITOR]);
    }
}
