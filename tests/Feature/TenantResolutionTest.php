<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves tenant by subdomain and blocks inactive tenants', function () {
    $service = app(\App\Services\Platform\TenantProvisioningService::class);

    $tenant = $service->provision([
        'name_en' => 'Active',
        'name_ar' => 'نشط',
        'slug' => 'active',
        'is_active' => true,
    ]);

    $inactiveTenant = $service->provision([
        'name_en' => 'Inactive',
        'name_ar' => 'غير نشط',
        'slug' => 'inactive',
        'is_active' => false,
    ]);

    // Call active tenant API route via header
    $response = $this->withHeader('X-Tenant', $tenant->public_id)
                     ->get('http://api.momentum.test/api/v1/');
    $response->assertStatus(200)
             ->assertJsonFragment(['tenant_id' => $tenant->public_id]);

    // Call inactive tenant API route via header
    $response = $this->withHeader('X-Tenant', $inactiveTenant->public_id)
                     ->get('http://api.momentum.test/api/v1/');
    $response->assertStatus(403);
});
