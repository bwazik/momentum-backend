<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('isolates data between two tenants', function () {
    $service = app(TenantProvisioningService::class);

    $tenant1 = $service->provision([
        'name_en' => 'Tenant 1',
        'name_ar' => 'تينانت ١',
        'slug' => 't1',
    ]);

    $tenant2 = $service->provision([
        'name_en' => 'Tenant 2',
        'name_ar' => 'تينانت ٢',
        'slug' => 't2',
    ]);

    tenancy()->initialize($tenant1);
    User::create([
        'name' => 'User One',
        'email' => 'user1@example.com',
        'password' => bcrypt('password'),
    ]);
    expect(User::count())->toBe(1);
    expect(User::first()->name)->toBe('User One');
    tenancy()->end();

    tenancy()->initialize($tenant2);
    // Tenant 2 should not see Tenant 1's user
    expect(User::count())->toBe(0);

    User::create([
        'name' => 'User Two',
        'email' => 'user2@example.com',
        'password' => bcrypt('password'),
    ]);
    expect(User::count())->toBe(1);
    expect(User::first()->name)->toBe('User Two');
    tenancy()->end();
});
