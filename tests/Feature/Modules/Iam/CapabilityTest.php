<?php

use App\Models\User;
use App\Modules\Iam\Models\Capability;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM Cap Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-cap-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(CapabilitySeeder::class);

    $this->adminUser = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $this->actingAs($this->adminUser);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('lists all capabilities', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/iam/capabilities');

    $response->assertOk()
        ->assertJsonCount(Capability::count());
});

it('shows a capability', function () {
    $cap = Capability::where('key', 'task.view.organization')->first();

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/iam/capabilities/{$cap->public_id}");

    $response->assertOk()
        ->assertJsonPath('key', 'task.view.organization');
});

it('updates a capability name', function () {
    $cap = Capability::where('key', 'task.view.organization')->first();

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/iam/capabilities/{$cap->public_id}", [
            'name_ar' => 'اسم جديد',
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'اسم جديد');
});

it('does not allow changing system-defined key', function () {
    $cap = Capability::where('key', 'task.view.organization')->first();

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/iam/capabilities/{$cap->public_id}", [
            'key' => 'new.key',
        ]);

    $response->assertStatus(422);
    expect($cap->fresh()->key)->toBe('task.view.organization');
});
