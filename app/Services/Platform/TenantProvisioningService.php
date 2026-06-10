<?php

namespace App\Services\Platform;

use App\Models\Tenant;

class TenantProvisioningService
{
    /**
     * Provision a new tenant database and run migrations.
     * Leveraging stancl/tenancy event pipeline.
     */
    public function provision(array $data): Tenant
    {
        $data['database_name'] = $data['database_name'] ?? config('tenancy.database.prefix').$data['slug'];

        $tenant = Tenant::create([
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
            'slug' => $data['slug'],
            'domain' => $data['domain'] ?? null,
            'database_name' => $data['database_name'],
            'logo_path' => $data['logo_path'] ?? null,
            'default_language' => $data['default_language'] ?? 1,
            'timezone' => $data['timezone'] ?? 'Asia/Riyadh',
            'is_active' => $data['is_active'] ?? true,
            'settings' => $data['settings'] ?? [],
        ]);

        $tenant->createDomain($data['slug']);

        return $tenant;
    }
}
