<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\PositionCapabilityGrant;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
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
    $this->tenant->delete();
});

it('returns department performance', function () {
    $department = Department::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $task->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee,
        'entered_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson("/v1/analytics/departments/{$department->public_id}/performance");

    $response->assertOk()
        ->assertJsonStructure(['department_public_id', 'active_tasks', 'overdue_tasks', 'at_risk_tasks', 'average_stage_delay_seconds']);
});

it('returns team metrics for a department', function () {
    $department = Department::factory()->create(['is_active' => true]);
    $position = Position::factory()->create(['department_id' => $department->id]);
    UserPositionAssignment::create([
        'user_id' => $this->user->id,
        'position_id' => $position->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);

    $orgCapability = Capability::where('key', 'analytics.view.organization')->first();
    if ($orgCapability) {
        PositionCapabilityGrant::create([
            'position_id' => $position->id,
            'capability_id' => $orgCapability->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
        ]);
    }

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson("/v1/analytics/departments/{$department->public_id}/team");

    $response->assertOk()
        ->assertJsonStructure(['*' => ['user_public_id', 'active_assignments']]);
});

it('returns drill-down for department tasks', function () {
    $department = Department::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $task->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee,
        'entered_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson("/v1/analytics/departments/{$department->public_id}/performance/drill-down");

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});

it('denies access for unauthorized user', function () {
    $department = Department::factory()->create(['is_active' => true]);
    $userWithoutCapability = User::factory()->create();
    $this->actingAs($userWithoutCapability, 'sanctum');

    $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson("/v1/analytics/departments/{$department->public_id}/performance")
        ->assertForbidden();
});
