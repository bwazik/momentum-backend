<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Test Tenant',
        'name_ar' => 'اختبار',
        'slug' => 'test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->tenantAdmin()->create();

    $orgCapability = Capability::where('key', 'analytics.view.organization')->first();
    if ($orgCapability) {
        UserCapabilityGrant::create([
            'user_id' => $this->user->id,
            'capability_id' => $orgCapability->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Test grant',
        ]);
    }

    $this->actingAs($this->user, 'sanctum');
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('returns aging report with cursor pagination', function () {
    Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    Task::factory()->suspended()->create(['initiator_user_id' => $this->user->id]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/tasks/aging');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});

it('filters aging report by priority', function () {
    $priority = TaskPriority::first();
    Task::factory()->active()->create([
        'priority_id' => $priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/tasks/aging?priority_id='.$priority->public_id);

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

it('excludes draft tasks from aging report', function () {
    Task::factory()->draft()->create(['initiator_user_id' => $this->user->id]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/tasks/aging');

    $response->assertOk();
    expect(count($response->json('data')))->toBe(0);
});
