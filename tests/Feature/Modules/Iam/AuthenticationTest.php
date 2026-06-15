<?php

use App\Enums\AccountType;
use App\Models\User;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM Auth Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-auth-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
});

afterEach(function () {
    User::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('can login with valid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertOk()
        ->assertJsonStructure(['public_id', 'name_ar', 'email']);
});

it('cannot login with invalid password', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

    $response->assertStatus(422);
});

it('cannot login with deactivated user', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password'),
        'is_active' => false,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertStatus(422);
});

it('cannot login with platform admin account type', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password'),
        'account_type' => AccountType::PLATFORM_ADMIN,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertStatus(422);
});

it('can logout', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->actingAs($user);

    $response = $this->withHeaders([
        'X-Tenant' => $this->tenant->public_id,
    ])->postJson('/v1/iam/auth/logout');

    $response->assertOk();
});

it('returns current user profile via me endpoint', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->actingAs($user);

    $response = $this->withHeaders([
        'X-Tenant' => $this->tenant->public_id,
    ])->getJson('/v1/iam/auth/me');

    $response->assertOk()
        ->assertJsonPath('public_id', $user->public_id);
});
