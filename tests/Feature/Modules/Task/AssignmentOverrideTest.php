<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\TaskStatus;
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
        'name_en' => 'Assignment Override Test',
        'name_ar' => 'اختبار',
        'slug' => 'ao-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->category = BlueprintCategory::factory()->create();
    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
    $this->priority = TaskPriority::where('is_default', true)->first();

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

    $this->token = $loginResponse->json('token');
    $this->authHeaders = [
        'Authorization' => "Bearer {$this->token}",
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('overrides stage assignment with task.override_assignment capability', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
    $newAssignee = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create([
        'user_id' => $assignee->id,
        'position_id' => $position->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);
    UserPositionAssignment::create([
        'user_id' => $newAssignee->id,
        'position_id' => $position->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);

    // Grant override_assignment capability to the acting user
    $capabilityId = Capability::where('key', 'task.override_assignment')->value('id');
    if ($capabilityId) {
        UserCapabilityGrant::create([
            'user_id' => $this->user->id,
            'capability_id' => $capabilityId,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Granted for test',
        ]);
    }

    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $stageType = StageType::first();
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $launchResponse = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch");

    $launchResponse->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    $overrideResponse = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/override-assignment", [
            'assignments' => [
                [
                    'current_user_id' => $assignee->public_id,
                    'new_user_id' => $newAssignee->public_id,
                ],
            ],
            'reason' => 'Assignee unavailable.',
        ]);

    $overrideResponse->assertOk();

    // Old assignment should be marked as reassigned
    $oldAssignment = $stageInstance->assignments()
        ->where('user_id', $assignee->id)
        ->first();
    expect($oldAssignment->reassigned_at)->not->toBeNull();
    expect($oldAssignment->reassignment_reason)->toBe('Assignee unavailable.');

    // New assignment should exist
    $newAssignment = $stageInstance->assignments()
        ->where('user_id', $newAssignee->id)
        ->whereNull('reassigned_at')
        ->first();
    expect($newAssignment)->not->toBeNull();
    expect($newAssignment->is_completed)->toBeFalse();
});

it('overrides sub-stage assignment with task.override_assignment capability', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
    $newAssignee = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create(['user_id' => $assignee->id, 'position_id' => $position->id, 'is_primary' => true, 'started_at' => now()]);
    UserPositionAssignment::create(['user_id' => $newAssignee->id, 'position_id' => $position->id, 'is_primary' => true, 'started_at' => now()]);

    $capabilityId = Capability::where('key', 'task.override_assignment')->value('id');
    if ($capabilityId) {
        UserCapabilityGrant::create(['user_id' => $this->user->id, 'capability_id' => $capabilityId, 'scope_type' => ScopeType::TENANT, 'granted_by_user_id' => $this->user->id, 'granted_at' => now(), 'reason' => 'Granted for test']);
    }

    $blueprint = Blueprint::factory()->create(['category_id' => $this->category->id, 'created_by_user_id' => $this->user->id, 'is_active' => true]);
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id, 'stage_type_id' => StageType::first()->id, 'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $stage->id, 'sequence_order' => 1, 'is_required' => true,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    $task = Task::factory()->create(['blueprint_id' => $blueprint->id, 'priority_id' => $this->priority->id, 'initiator_user_id' => $this->user->id, 'status' => TaskStatus::Draft]);
    $this->withHeaders($this->authHeaders)->postJson("/v1/tasks/{$task->public_id}/launch")->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();
    $subStageInstance = $stageInstance->subStageInstances()->first();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/sub-stages/{$subStageInstance->id}/override-assignment", [
            'assignments' => [['current_user_id' => $assignee->public_id, 'new_user_id' => $newAssignee->public_id]],
            'reason' => 'Assignee unavailable.',
        ]);
    $response->assertOk();

    $oldAssignment = $subStageInstance->assignments()->where('user_id', $assignee->id)->first();
    expect($oldAssignment->reassigned_at)->not->toBeNull();
    expect($oldAssignment->reassignment_reason)->toBe('Assignee unavailable.');

    $newAssignment = $subStageInstance->assignments()->where('user_id', $newAssignee->id)->whereNull('reassigned_at')->first();
    expect($newAssignment)->not->toBeNull();
    expect($newAssignment->is_completed)->toBeFalse();
});

it('rejects override without task.override_assignment capability', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
    $newAssignee = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create([
        'user_id' => $assignee->id,
        'position_id' => $position->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);

    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $stageType = StageType::first();
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $launchResponse = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch");

    $launchResponse->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    // User without override_assignment capability
    $noCapUser = User::factory()->create(['is_active' => true]);
    $noCapLogin = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', ['email' => $noCapUser->email, 'password' => 'password']);
    $noCapToken = $noCapLogin->json('token');
    $noCapHeaders = [
        'Authorization' => "Bearer {$noCapToken}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $overrideResponse = $this->withHeaders($noCapHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/override-assignment", [
            'assignments' => [
                [
                    'current_user_id' => $assignee->public_id,
                    'new_user_id' => $newAssignee->public_id,
                ],
            ],
            'reason' => 'Should fail.',
        ]);

    $overrideResponse->assertForbidden();
});
