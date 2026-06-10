<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name_ar' => 'عبدالله',
            'name_en' => 'Abdullah',
            'email' => 'abdullah-swe@outlook.com',
            'password' => 'password123',
            'account_type' => AccountType::PLATFORM_ADMIN,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command->info('Platform admin created: abdullah-swe@outlook.com / password123');
    }
}
