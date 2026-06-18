<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'abdullah-swe@outlook.com'],
            [
                'name_ar' => 'عبدالله',
                'name_en' => 'Abdullah',
                'password' => 'password123',
                'account_type' => AccountType::PLATFORM_ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->command->info('Platform admin ready: abdullah-swe@outlook.com / password123');
    }
}
