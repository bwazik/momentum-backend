<?php

use App\Models\Tenant;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves tenant by subdomain and blocks inactive tenants', function () {
    $service = app(TenantProvisioningService::class);

    $tenant = $service->provision([
        'name_en' => 'Active',
        'name_ar' => 'نشط',
        'slug' => 'active-'.uniqid(),
        'is_active' => true,
    ]);

    $inactiveTenant = $service->provision([
        'name_en' => 'Inactive',
        'name_ar' => 'غير نشط',
        'slug' => 'inactive-'.uniqid(),
        'is_active' => false,
    ]);

    // Verify tenancy initializes for the active tenant
    tenancy()->initialize($tenant);
    expect(tenancy()->tenant->public_id)->toBe($tenant->public_id);
    tenancy()->end();

    // Verify tenancy initializes for the inactive tenant
    tenancy()->initialize($inactiveTenant);
    expect(tenancy()->tenant->public_id)->toBe($inactiveTenant->public_id);
    expect($inactiveTenant->is_active)->toBeFalse();
    tenancy()->end();

    // Call active tenant API route via header
    $response = $this->withHeader('X-Tenant', $tenant->public_id)
        ->get('http://api.momentum.test/v1');
    $response->assertStatus(200)
        ->assertJsonPath('tenant.public_id', $tenant->public_id);

    // End tenancy and revert default connection before inactive request
    tenancy()->end();

    // Call inactive tenant API route via header - should be blocked
    $response = $this->withHeader('X-Tenant', $inactiveTenant->public_id)
        ->get('http://api.momentum.test/v1');
    $response->assertStatus(403);

    // End tenancy so teardown doesn't try to connect to tenant database
    tenancy()->end();

    cleanupTenantDatabase($tenant->database_name);
    cleanupTenantDatabase($inactiveTenant->database_name);
});
