<?php

use App\Models\Tenant;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('provisions a new tenant with a database and domains', function () {
    $service = app(TenantProvisioningService::class);

    $tenant = $service->provision([
        'name_en' => 'Ministry of Finance',
        'name_ar' => 'وزارة المالية',
        'slug' => 'mof',
    ]);

    expect($tenant)->toBeInstanceOf(Tenant::class)
        ->slug->toBe('mof')
        ->database_name->toBe('momentum_tenant_mof')
        ->public_id->not->toBeNull();

    expect($tenant->domains)->toHaveCount(1)
        ->first()->domain->toBe('mof');

    // Tenancy package creates the database. Let's verify it exists and is migrated.
    // By using the tenanted mode:
    tenancy()->initialize($tenant);

    // Check that users table exists in the tenant database
    expect(Schema::hasTable('users'))->toBeTrue();

    tenancy()->end();

    // Clean up
    cleanupTenantDatabase($tenant->database_name);
    $tenant->delete();
});
