<?php

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'name_ar' => 'مدير المنصة',
        'name_en' => 'Platform Admin',
        'email' => 'admin@momentum.test',
        'password' => bcrypt('password'),
        'account_type' => AccountType::PLATFORM_ADMIN,
        'is_active' => true,
    ]);
});

it('can login with valid platform admin credentials', function () {
    $response = $this->postJson('/v1/platform/auth/login', [
        'email' => 'admin@momentum.test',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user' => ['public_id', 'name_ar', 'email']]);
});

it('cannot login with invalid credentials', function () {
    $response = $this->postJson('/v1/platform/auth/login', [
        'email' => 'admin@momentum.test',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
});

it('cannot login with tenant admin account', function () {
    User::factory()->create([
        'email' => 'tenant@momentum.test',
        'password' => bcrypt('password'),
        'account_type' => AccountType::TENANT_ADMIN,
    ]);

    $response = $this->postJson('/v1/platform/auth/login', [
        'email' => 'tenant@momentum.test',
        'password' => 'password',
    ]);

    $response->assertStatus(422);
});

it('can access me endpoint with valid session', function () {
    $this->actingAs($this->admin);

    $response = $this->getJson('/v1/platform/auth/me');

    $response->assertOk()
        ->assertJsonPath('public_id', $this->admin->public_id);
});

it('can logout', function () {
    $this->actingAs($this->admin);

    $response = $this->postJson('/v1/platform/auth/logout');

    $response->assertOk();
});
