<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\Delegation;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Active Del Test',
        'name_ar' => 'اختبار',
        'slug' => 'act-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->create();
    $this->delegator = User::factory()->create();
    $this->delegate = User::factory()->create();

    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('returns only currently active delegations', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDay(),
        'scope_type' => 1,
        'is_active' => false,
    ]);

    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
        'is_active' => true,
    ]);

    $viewDelegations = Capability::where('key', 'iam.view_delegations')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $viewDelegations->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'test',
    ]);

    $response = $this->actingAs($this->user)->withHeaders($this->authHeaders)
        ->getJson('/v1/iam/delegations/active');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(1, 'data');
});

it('filters by delegator_user_id', function () {
    $otherDelegator = User::factory()->create();

    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
        'is_active' => true,
    ]);

    Delegation::create([
        'delegator_user_id' => $otherDelegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
        'is_active' => true,
    ]);

    $viewDelegations = Capability::where('key', 'iam.view_delegations')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $viewDelegations->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'test',
    ]);

    $response = $this->actingAs($this->user)->withHeaders($this->authHeaders)
        ->getJson('/v1/iam/delegations/active?delegator_user_id='.$this->delegator->public_id);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('requires iam.view_delegations or iam.manage_users capability', function () {
    $response = $this->actingAs($this->user)->withHeaders($this->authHeaders)
        ->getJson('/v1/iam/delegations/active');

    $response->assertForbidden();
});

it('validates filter parameters', function () {
    $viewDelegations = Capability::where('key', 'iam.view_delegations')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $viewDelegations->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'test',
    ]);

    $response = $this->actingAs($this->user)->withHeaders($this->authHeaders)
        ->getJson('/v1/iam/delegations/active?per_page=999');

    $response->assertStatus(422);
});

it('supports active_now filter on index endpoint', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDay(),
        'scope_type' => 1,
        'is_active' => false,
    ]);

    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
        'is_active' => true,
    ]);

    $viewDelegations = Capability::where('key', 'iam.view_delegations')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $viewDelegations->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'test',
    ]);

    $response = $this->actingAs($this->user)->withHeaders($this->authHeaders)
        ->getJson('/v1/iam/delegations?active_now=1');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(1, 'data');
});
