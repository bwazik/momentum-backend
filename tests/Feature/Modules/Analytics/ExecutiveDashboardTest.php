<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Models\Task;
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

it('returns executive summary counts', function () {
    Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    Task::factory()->suspended()->create(['initiator_user_id' => $this->user->id]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/executive/summary');

    $response->assertOk()
        ->assertJsonPath('active', 1)
        ->assertJsonPath('suspended', 1)
        ->assertJsonStructure(['active', 'overdue', 'at_risk', 'suspended', 'completed', 'cancelled', 'completion_rate']);
});

it('returns department health', function () {
    $department = Department::factory()->create(['is_active' => true]);
    $active = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $active->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee,
        'entered_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/executive/department-health');

    $response->assertOk()
        ->assertJsonStructure(['*' => ['department_public_id', 'health', 'health_label', 'active_tasks']]);
});

it('returns bottlenecks', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/executive/bottlenecks');

    $response->assertOk();
});

it('returns drill-down for active tasks', function () {
    Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/executive/summary/drill-down/active');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});

it('denies access without analytics capability', function () {
    $userWithoutCapability = User::factory()->create();
    $this->actingAs($userWithoutCapability, 'sanctum');

    $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/executive/summary')
        ->assertForbidden();
});
