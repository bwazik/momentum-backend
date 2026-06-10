<?php

use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Models\User;
use App\Modules\Platform\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'name_ar' => 'مدير التدقيق',
        'name_en' => 'Audit Admin',
        'email' => 'audit@momentum.test',
        'password' => bcrypt('password'),
        'account_type' => AccountType::PLATFORM_ADMIN,
        'is_active' => true,
    ]);

    $loginResponse = $this->postJson('/v1/platform/auth/login', [
        'email' => 'audit@momentum.test',
        'password' => 'password',
    ]);

    $this->token = $loginResponse->json('token');
});

it('can list audit events with cursor pagination', function () {
    AuditEvent::create([
        'public_id' => (string) Str::uuid7(),
        'user_id' => $this->admin->id,
        'action' => AuditAction::PlatformLogin->value,
        'entity_type' => 'platform_admin',
        'entity_id' => $this->admin->public_id,
        'payload' => ['ip_address' => '127.0.0.1'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/v1/platform/audit-events');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});

it('can filter audit events by action', function () {
    AuditEvent::create([
        'public_id' => (string) Str::uuid7(),
        'user_id' => $this->admin->id,
        'action' => AuditAction::PlatformAdminCreate->value,
        'entity_type' => 'platform_admin',
        'entity_id' => $this->admin->public_id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/v1/platform/audit-events?action='.AuditAction::PlatformAdminCreate->value);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});
