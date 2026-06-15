<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Tracking\Enums\EscalationStatus;
use App\Modules\Tracking\Models\Escalation;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Manual Escalation Test',
        'name_ar' => 'اختبار',
        'slug' => 'me-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->calendar = WorkingCalendar::factory()->create(['is_default' => true]);
    $this->category = BlueprintCategory::factory()->create();

    $this->department = Department::factory()->create();
    $this->targetPosition = Position::factory()->create([
        'department_id' => $this->department->id,
        'is_active' => true,
    ]);
    $this->targetUser = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create([
        'user_id' => $this->targetUser->id,
        'position_id' => $this->targetPosition->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->task = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $stageType = StageType::first();
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
    ]);
    $this->stageInstance = TaskStageInstance::create([
        'task_id' => $this->task->id,
        'blueprint_stage_id' => $stage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
        'status' => StageInstanceStatus::Active,
        'entered_at' => now(),
    ]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('creates manual escalation with reason', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tracking/escalations', [
            'task_id' => $this->task->public_id,
            'stage_instance_id' => $this->stageInstance->public_id,
            'reason' => 'This stage is at risk and needs attention.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('reason', 'This stage is at risk and needs attention.')
        ->assertJsonStructure([
            'public_id', 'task_id', 'reason', 'status', 'escalation_type',
        ]);
});

it('rejects duplicate open manual escalation from same user', function () {
    $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tracking/escalations', [
            'task_id' => $this->task->public_id,
            'stage_instance_id' => $this->stageInstance->public_id,
            'reason' => 'At risk.',
        ])->assertCreated();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tracking/escalations', [
            'task_id' => $this->task->public_id,
            'stage_instance_id' => $this->stageInstance->public_id,
            'reason' => 'Still at risk.',
        ]);

    $response->assertUnprocessable();
});

it('resolves escalation by target user', function () {
    $escalation = Escalation::factory()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $this->stageInstance->id,
        'escalated_to_user_id' => $this->user->id,
        'status' => EscalationStatus::Open,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tracking/escalations/{$escalation->public_id}/resolve", [
            'resolution_note' => 'Issue resolved through discussion.',
        ]);

    $response->assertOk()
        ->assertJsonPath('status', EscalationStatus::Resolved->value)
        ->assertJsonPath('resolution_note', 'Issue resolved through discussion.');
});

it('rejects resolving already resolved escalation', function () {
    $escalation = Escalation::factory()->resolved()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $this->stageInstance->id,
        'escalated_to_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tracking/escalations/{$escalation->public_id}/resolve", [
            'resolution_note' => 'Try to resolve again.',
        ]);

    $response->assertUnprocessable();
});

it('denies unauthorized user from resolving escalation', function () {
    $otherUser = User::factory()->create(['password' => bcrypt('password')]);

    // Grant task visibility so authorizeTaskVisibility passes,
    // but NOT task.resolve_escalations so the service ABAC check fails
    $orgViewCapability = Capability::where('key', 'task.view.organization')->first();
    UserCapabilityGrant::create([
        'user_id' => $otherUser->id,
        'capability_id' => $orgViewCapability->id,
        'scope_type' => ScopeType::TENANT->value,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test task visibility grant',
    ]);

    $this->actingAs($otherUser);
    $otherHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];

    $escalation = Escalation::factory()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $this->stageInstance->id,
        'escalated_to_user_id' => $this->user->id,
        'status' => EscalationStatus::Open,
    ]);

    $response = $this->withHeaders($otherHeaders)
        ->postJson("/v1/tracking/escalations/{$escalation->public_id}/resolve", [
            'resolution_note' => 'Unauthorized resolve.',
        ]);

    $response->assertForbidden();
});
