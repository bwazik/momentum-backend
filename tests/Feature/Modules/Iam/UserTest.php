<?php

use App\Models\User;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM User Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-user-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->adminUser = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $this->actingAs($this->adminUser);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    User::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('lists users with pagination', function () {
    User::factory()->count(3)->create();

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/iam/users');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});

it('creates a user', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/users', [
            'name_ar' => 'موظف جديد',
            'name_en' => 'New Employee',
            'email' => 'new@example.com',
            'password' => 'password123',
            'account_type' => 1,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('name_ar', 'موظف جديد')
        ->assertJsonPath('name_en', 'New Employee')
        ->assertJsonStructure(['public_id']);
});

it('prevents creating user with account_type 4', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/users', [
            'name_ar' => 'منصة',
            'email' => 'platform@example.com',
            'password' => 'password123',
            'account_type' => 4,
        ]);

    $response->assertStatus(422);
});

it('shows user detail', function () {
    $user = User::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/iam/users/{$user->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $user->public_id);
});

it('updates a user', function () {
    $user = User::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/iam/users/{$user->public_id}", [
            'name_ar' => 'اسم محدث',
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'اسم محدث');
});

it('deactivates a user', function () {
    $user = User::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/iam/users/{$user->public_id}/deactivate");

    $response->assertOk();
    expect($user->fresh()->is_active)->toBeFalse();
    expect($user->fresh()->deleted_at)->not->toBeNull();
});

it('reactivates a user', function () {
    $user = User::factory()->create(['is_active' => false]);
    $user->delete();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/iam/users/{$user->public_id}/reactivate");

    $response->assertOk();
    expect($user->fresh()->is_active)->toBeTrue();
    expect($user->fresh()->deleted_at)->toBeNull();
});

it('cannot deactivate an already deactivated user', function () {
    $user = User::factory()->create(['is_active' => false]);
    $user->delete();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/iam/users/{$user->public_id}/deactivate");

    $response->assertStatus(422);
});

it('cannot reactivate an already active user', function () {
    $user = User::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/iam/users/{$user->public_id}/reactivate");

    $response->assertStatus(422);
});
