<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\MonitoringScopeGrant;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Enums\ClassificationLevel;
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

    $this->user = User::factory()->create();

    $this->actingAs($this->user, 'sanctum');
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

function grantCapability(User $user, string $key, ?ScopeType $scopeType = null, ?int $departmentId = null): void
{
    $capability = Capability::where('key', $key)->firstOrFail();

    UserCapabilityGrant::create([
        'user_id' => $user->id,
        'capability_id' => $capability->id,
        'scope_type' => $scopeType ?? ScopeType::TENANT,
        'scope_department_id' => $departmentId,
        'granted_by_user_id' => $user->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);
}

it('denies executive summary without analytics view organization capability', function () {
    $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/executive/summary')
        ->assertForbidden();
});

it('denies department performance without analytics view department capability', function () {
    $department = Department::factory()->create(['is_active' => true]);

    $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson("/v1/analytics/departments/{$department->public_id}/performance")
        ->assertForbidden();
});

it('filters aging report by monitoring scope', function () {
    grantCapability($this->user, 'task.view.follow_up_scope');

    $departmentA = Department::factory()->create(['is_active' => true]);
    $departmentB = Department::factory()->create(['is_active' => true]);
    $otherUser = User::factory()->create();

    MonitoringScopeGrant::create([
        'user_id' => $this->user->id,
        'scope_type' => ScopeType::SPECIFIC_DEPARTMENT,
        'scope_department_id' => $departmentA->id,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
    ]);

    $taskInScope = Task::factory()->active()->create(['initiator_user_id' => $otherUser->id]);
    $taskInScope->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $departmentA->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee,
        'entered_at' => now(),
    ]);

    $taskOutOfScope = Task::factory()->active()->create(['initiator_user_id' => $otherUser->id]);
    $taskOutOfScope->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $departmentB->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee,
        'entered_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/tasks/aging');

    $response->assertOk();
    expect(count($response->json('data')))->toBe(1);
    expect($response->json('data.0.task_public_id'))->toBe($taskInScope->public_id);
});

it('excludes confidential tasks from users without confidential view metadata capability', function () {
    grantCapability($this->user, 'analytics.view.organization');
    grantCapability($this->user, 'task.view.organization');

    $otherUser = User::factory()->create();

    $publicTask = Task::factory()->active()->create([
        'initiator_user_id' => $otherUser->id,
        'classification_level' => ClassificationLevel::Public,
    ]);

    $confidentialTask = Task::factory()->active()->create([
        'initiator_user_id' => $otherUser->id,
        'classification_level' => ClassificationLevel::Confidential,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/executive/summary');

    $response->assertOk()
        ->assertJsonPath('active', 1);
});

it('includes confidential metadata for users with confidential view metadata capability', function () {
    grantCapability($this->user, 'analytics.view.organization');
    grantCapability($this->user, 'task.view.organization');
    grantCapability($this->user, 'task.confidential.view_metadata');

    $otherUser = User::factory()->create();

    Task::factory()->active()->create([
        'initiator_user_id' => $otherUser->id,
        'classification_level' => ClassificationLevel::Public,
    ]);

    Task::factory()->active()->create([
        'initiator_user_id' => $otherUser->id,
        'classification_level' => ClassificationLevel::Confidential,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/analytics/executive/summary');

    $response->assertOk()
        ->assertJsonPath('active', 2);
});

it('restricts department performance to scoped department', function () {
    $departmentA = Department::factory()->create(['is_active' => true]);
    $departmentB = Department::factory()->create(['is_active' => true]);

    grantCapability($this->user, 'analytics.view.department', ScopeType::SPECIFIC_DEPARTMENT, $departmentA->id);
    grantCapability($this->user, 'task.view.organization');

    $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson("/v1/analytics/departments/{$departmentA->public_id}/performance")
        ->assertOk();

    $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson("/v1/analytics/departments/{$departmentB->public_id}/performance")
        ->assertForbidden();
});
