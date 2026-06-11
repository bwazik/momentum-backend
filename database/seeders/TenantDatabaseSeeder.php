<?php

namespace Database\Seeders;

use App\Modules\Blueprint\Models\StageType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        StageType::insert([
            ['public_id' => (string) Str::uuid7(), 'name_en' => 'Action', 'name_ar' => 'إجراء', 'is_system_default' => true, 'is_active' => true, 'display_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::uuid7(), 'name_en' => 'Review', 'name_ar' => 'مراجعة', 'is_system_default' => true, 'is_active' => true, 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::uuid7(), 'name_en' => 'Approval', 'name_ar' => 'موافقة', 'is_system_default' => true, 'is_active' => true, 'display_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::uuid7(), 'name_en' => 'Decision', 'name_ar' => 'قرار', 'is_system_default' => true, 'is_active' => true, 'display_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::uuid7(), 'name_en' => 'Information Gathering', 'name_ar' => 'جمع المعلومات', 'is_system_default' => true, 'is_active' => true, 'display_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
