<?php

use App\Models\User;
use App\Modules\Task\Models\TaskPriority;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Task Priority Test',
        'name_ar' => 'اختبار',
        'slug' => 'tp-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->tenantAdmin()->create();
    $this->userWithoutCapability = User::factory()->create();

    $this->actingAs($this->user, 'sanctum');
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->userWithoutCapabilityId = $this->userWithoutCapability->id;
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('lists active priorities', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tasks/priorities');

    $response->assertOk()
        ->assertJsonCount(3);
});

it('creates priority with admin user', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks/priorities', [
            'name_ar' => 'عاجل جداً',
            'name_en' => 'Very Urgent',
            'severity_rank' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('name_ar', 'عاجل جداً');

    $this->assertDatabaseHas('task_priorities', [
        'name_ar' => 'عاجل جداً',
        'severity_rank' => 1,
    ]);
});

it('creates priority without capability returns 403', function () {
    $this->actingAs($this->userWithoutCapability, 'sanctum');
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/tasks/priorities', [
            'name_ar' => 'عاجل جداً',
            'severity_rank' => 1,
        ]);

    $response->assertForbidden();
});

it('creates priority missing name_ar returns 422', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks/priorities', [
            'severity_rank' => 1,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('name_ar');
});

it('updates priority', function () {
    $priority = TaskPriority::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/tasks/priorities/{$priority->public_id}", [
            'name_ar' => 'محدث',
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'محدث');
});

it('deactivates and reactivates priority', function () {
    $priority = TaskPriority::factory()->create(['is_active' => true]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/priorities/{$priority->public_id}/deactivate");

    $response->assertOk()
        ->assertJsonPath('is_active', false);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/priorities/{$priority->public_id}/reactivate");

    $response->assertOk()
        ->assertJsonPath('is_active', true);
});

it('swaps default priority on create', function () {
    TaskPriority::factory()->default()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks/priorities', [
            'name_ar' => 'جديد افتراضي',
            'severity_rank' => 1,
            'is_default' => true,
        ]);

    $response->assertCreated();

    $this->assertDatabaseCount('task_priorities', 5);
});
