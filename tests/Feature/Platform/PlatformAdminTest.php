<?php

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'name_ar' => 'المدير الرئيسي',
        'name_en' => 'Main Admin',
        'email' => 'main@momentum.test',
        'password' => bcrypt('password'),
        'account_type' => AccountType::PLATFORM_ADMIN,
        'is_active' => true,
    ]);

    $loginResponse = $this->postJson('/v1/platform/auth/login', [
        'email' => 'main@momentum.test',
        'password' => 'password',
    ]);

    $this->token = $loginResponse->json('token');
});

it('can list platform admins', function () {
    User::factory()->create([
        'account_type' => AccountType::PLATFORM_ADMIN,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/v1/platform/admins');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});

it('can create a platform admin', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/v1/platform/admins', [
            'name_ar' => 'مشرف جديد',
            'name_en' => 'New Admin',
            'email' => 'new@momentum.test',
            'password' => 'password123',
        ]);

    $response->assertCreated()
        ->assertJsonPath('email', 'new@momentum.test');

    $this->assertDatabaseHas('users', [
        'email' => 'new@momentum.test',
        'account_type' => AccountType::PLATFORM_ADMIN->value,
    ]);
});

it('cannot create platform admin with duplicate email', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/v1/platform/admins', [
            'name_ar' => 'مكرر',
            'name_en' => 'Duplicate',
            'email' => 'main@momentum.test',
            'password' => 'password123',
        ]);

    $response->assertStatus(422);
});

it('can show a platform admin', function () {
    $target = User::factory()->create([
        'account_type' => AccountType::PLATFORM_ADMIN,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/v1/platform/admins/{$target->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $target->public_id);
});

it('can update a platform admin', function () {
    $target = User::factory()->create([
        'account_type' => AccountType::PLATFORM_ADMIN,
        'name_ar' => 'قديم',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/v1/platform/admins/{$target->public_id}", [
            'name_ar' => 'محدث',
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'محدث');
});

it('can deactivate and reactivate a platform admin', function () {
    $target = User::factory()->create([
        'account_type' => AccountType::PLATFORM_ADMIN,
        'is_active' => true,
    ]);

    $deactivateResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/v1/platform/admins/{$target->public_id}/deactivate");

    $deactivateResponse->assertOk();
    expect($target->fresh()->is_active)->toBeFalse();

    $reactivateResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/v1/platform/admins/{$target->public_id}/reactivate");

    $reactivateResponse->assertOk();
    expect($target->fresh()->is_active)->toBeTrue();
});

it('cannot deactivate self', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/v1/platform/admins/{$this->admin->public_id}/deactivate");

    $response->assertStatus(422);
});
