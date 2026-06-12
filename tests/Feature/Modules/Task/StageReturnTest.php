<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Enums\TransitionType;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Blueprint\Models\BlueprintTransition;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\SubStageInstanceStatus;
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
        'name_en' => 'Stage Return Test',
        'name_ar' => 'اختبار',
        'slug' => 'sr-test-'.uniqid(),
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

    $this->stageType = StageType::first();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('returns stage to valid target and creates new stage instance', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
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

    $stage1 = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    $stage2 = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 2,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    BlueprintTransition::create([
        'blueprint_id' => $blueprint->id,
        'from_stage_id' => $stage1->id,
        'to_stage_id' => $stage2->id,
        'transition_type' => TransitionType::Advance,
    ]);
    BlueprintTransition::create([
        'blueprint_id' => $blueprint->id,
        'from_stage_id' => $stage2->id,
        'to_stage_id' => $stage1->id,
        'transition_type' => TransitionType::Return,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch")
        ->assertOk();

    $stage1Instance = $task->fresh()->stageInstances()->where('sequence_order', 1)->first();
    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stage1Instance->id}/complete");

    $stage2Instance = $task->fresh()->stageInstances()->where('sequence_order', 2)->first();
    expect($stage2Instance)->not->toBeNull();
    expect($stage2Instance->status)->toBe(StageInstanceStatus::Active);

    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stage2Instance->id}/return", [
            'target_stage_id' => $stage1->public_id,
            'reason' => 'Need revisions.',
        ])
        ->assertOk();

    expect($stage2Instance->fresh()->status)->toBe(StageInstanceStatus::Returned);

    $newStage1Instance = $task->fresh()->stageInstances()
        ->where('sequence_order', 1)
        ->where('status', StageInstanceStatus::Active)
        ->first();
    expect($newStage1Instance)->not->toBeNull();
    expect($newStage1Instance->id)->not->toBe($stage1Instance->id);
});

it('rejects return to invalid target with no transition defined', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
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

    $stage1 = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    $stage2 = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 2,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    $stage3 = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 3,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    BlueprintTransition::create([
        'blueprint_id' => $blueprint->id,
        'from_stage_id' => $stage1->id,
        'to_stage_id' => $stage2->id,
        'transition_type' => TransitionType::Advance,
    ]);
    BlueprintTransition::create([
        'blueprint_id' => $blueprint->id,
        'from_stage_id' => $stage2->id,
        'to_stage_id' => $stage3->id,
        'transition_type' => TransitionType::Advance,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch")
        ->assertOk();

    $stage1Instance = $task->fresh()->stageInstances()->where('sequence_order', 1)->first();
    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stage1Instance->id}/complete");

    $stage2Instance = $task->fresh()->stageInstances()->where('sequence_order', 2)->first();

    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stage2Instance->id}/return", [
            'target_stage_id' => $stage3->public_id,
            'reason' => 'Should fail.',
        ])
        ->assertUnprocessable();
});

it('returns sub-stage to earlier sub-stage within same parent stage', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create(['user_id' => $assignee->id, 'position_id' => $position->id, 'is_primary' => true, 'started_at' => now()]);

    $blueprint = Blueprint::factory()->create(['category_id' => $this->category->id, 'created_by_user_id' => $this->user->id, 'is_active' => true]);
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id, 'stage_type_id' => $this->stageType->id, 'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    $subStage1 = BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $stage->id, 'sequence_order' => 1, 'is_required' => true,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $stage->id, 'sequence_order' => 2, 'is_required' => true,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    $task = Task::factory()->create(['blueprint_id' => $blueprint->id, 'priority_id' => $this->priority->id, 'initiator_user_id' => $this->user->id, 'status' => TaskStatus::Draft]);
    $this->withHeaders($this->authHeaders)->postJson("/v1/tasks/{$task->public_id}/launch")->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();
    $sub1 = $stageInstance->subStageInstances()->where('sequence_order', 1)->first();
    $originalSub2 = $stageInstance->subStageInstances()->where('sequence_order', 2)->first();

    // Complete sub-stage 1 to activate sub-stage 2
    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/sub-stages/{$sub1->id}/complete")->assertOk();

    // Fetch the newly created active sub-stage 2 instance (original was Pending)
    $activeSub2 = $stageInstance->fresh()->subStageInstances()
        ->where('sequence_order', 2)
        ->where('status', SubStageInstanceStatus::Active->value)
        ->first();
    expect($activeSub2)->not->toBeNull();

    // Return sub-stage 2 back to sub-stage 1
    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/sub-stages/{$activeSub2->id}/return", [
            'target_sub_stage_id' => $subStage1->public_id,
            'reason' => 'Need changes.',
        ])->assertOk();

    expect($activeSub2->fresh()->status)->toBe(SubStageInstanceStatus::Returned);

    $newSub1 = $stageInstance->fresh()->subStageInstances()
        ->where('blueprint_sub_stage_id', $subStage1->id)
        ->where('status', SubStageInstanceStatus::Active->value)
        ->first();
    expect($newSub1)->not->toBeNull();
    expect($newSub1->id)->not->toBe($sub1->id);
});

it('rejects sub-stage return to invalid target with higher sequence_order', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create(['user_id' => $assignee->id, 'position_id' => $position->id, 'is_primary' => true, 'started_at' => now()]);

    $blueprint = Blueprint::factory()->create(['category_id' => $this->category->id, 'created_by_user_id' => $this->user->id, 'is_active' => true]);
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id, 'stage_type_id' => $this->stageType->id, 'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $stage->id, 'sequence_order' => 1, 'is_required' => true,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    $subStage2 = BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $stage->id, 'sequence_order' => 2, 'is_required' => true,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    $task = Task::factory()->create(['blueprint_id' => $blueprint->id, 'priority_id' => $this->priority->id, 'initiator_user_id' => $this->user->id, 'status' => TaskStatus::Draft]);
    $this->withHeaders($this->authHeaders)->postJson("/v1/tasks/{$task->public_id}/launch")->assertOk();

    $sub1 = $task->fresh()->stageInstances()->first()->subStageInstances()->where('sequence_order', 1)->first();

    // Try to return sub-stage 1 to sub-stage 2 (higher sequence_order) — should fail
    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/sub-stages/{$sub1->id}/return", [
            'target_sub_stage_id' => $subStage2->public_id,
            'reason' => 'Should fail.',
        ])->assertUnprocessable();
});

it('cancels active sub-stages when stage is returned', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
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

    $stage1 = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    $stage2 = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 2,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    BlueprintTransition::create([
        'blueprint_id' => $blueprint->id,
        'from_stage_id' => $stage1->id,
        'to_stage_id' => $stage2->id,
        'transition_type' => TransitionType::Advance,
    ]);
    BlueprintTransition::create([
        'blueprint_id' => $blueprint->id,
        'from_stage_id' => $stage2->id,
        'to_stage_id' => $stage1->id,
        'transition_type' => TransitionType::Return,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch")
        ->assertOk();

    $stage1Instance = $task->fresh()->stageInstances()->where('sequence_order', 1)->first();
    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stage1Instance->id}/complete");

    $stage2Instance = $task->fresh()->stageInstances()->where('sequence_order', 2)->first();

    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stage2Instance->id}/return", [
            'target_stage_id' => $stage1->public_id,
            'reason' => 'Need changes.',
        ]);

    expect($stage2Instance->fresh()->status)->toBe(StageInstanceStatus::Returned);
});
