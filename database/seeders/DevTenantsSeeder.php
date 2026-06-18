<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevTenantsSeeder extends Seeder
{
    public function run(): void
    {
        $prefix = config('tenancy.database.prefix');
        $provisioner = app(TenantProvisioningService::class);

        $tenants = [
            [
                'name_en' => 'Ministry of Finance',
                'name_ar' => 'وزارة المالية',
                'slug' => 'mof',
            ],
            [
                'name_en' => 'Ministry of Health',
                'name_ar' => 'وزارة الصحة',
                'slug' => 'moh',
            ],
            [
                'name_en' => 'Ministry of Education',
                'name_ar' => 'وزارة التعليم',
                'slug' => 'moe',
            ],
            [
                'name_en' => 'Ministry of Interior',
                'name_ar' => 'وزارة الداخلية',
                'slug' => 'moi',
            ],
        ];

        $this->command->info('Dropping existing tenant databases & records...');

        foreach ($tenants as $data) {
            $dbName = $prefix.$data['slug'];

            DB::statement("DROP DATABASE IF EXISTS \"{$dbName}\"");

            $this->command->info("  Dropped database: {$dbName}");
        }

        $slugs = array_column($tenants, 'slug');
        Tenant::whereIn('slug', $slugs)->forceDelete();

        foreach ($tenants as $data) {
            $tenant = $provisioner->provision($data);

            tenancy()->initialize($tenant);

            $this->call(CapabilitySeeder::class, false, []);

            User::create([
                'name_ar' => 'مدير النظام',
                'name_en' => 'System Admin',
                'email' => 'admin@'.$tenant->slug.'.test',
                'password' => 'password123',
                'account_type' => 2,
                'is_active' => true,
            ]);

            $this->call(TenantDatabaseSeeder::class, false, []);

            tenancy()->end();

            $this->command->info("Created tenant: {$tenant->name_en} ({$tenant->slug})");
            $this->command->info("  Tenant ID: {$tenant->public_id}");
            $this->command->info("  Admin: admin@{$tenant->slug}.test / password123");
        }
    }
}
