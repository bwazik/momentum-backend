<?php

namespace Database\Seeders;

use App\Modules\Blueprint\Models\StageType;
use App\Modules\Task\Models\TaskPriority;
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

        TaskPriority::insert([
            ['public_id' => (string) Str::uuid7(), 'name_en' => 'Critical', 'name_ar' => 'حرجة', 'severity_rank' => 1, 'color_code' => '#DC2626', 'is_default' => false, 'is_active' => true, 'display_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::uuid7(), 'name_en' => 'Urgent', 'name_ar' => 'عاجلة', 'severity_rank' => 2, 'color_code' => '#F59E0B', 'is_default' => false, 'is_active' => true, 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::uuid7(), 'name_en' => 'Routine', 'name_ar' => 'روتينية', 'severity_rank' => 3, 'color_code' => '#10B981', 'is_default' => true, 'is_active' => true, 'display_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        if (app()->environment('local', 'testing')) {
            $this->call(MockDataSeeder::class);
        }
    }
}
