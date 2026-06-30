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
use App\Modules\Task\Enums\AssignmentRole;
use App\Modules\Task\Enums\StageInstanceStatus;
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
        'name_en' => 'Stage Complete Test',
        'name_ar' => 'اختبار',
        'slug' => 'sc-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->category = BlueprintCategory::factory()->create();
    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
    $this->priority = TaskPriority::where('is_default', true)->first();

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->stageType = StageType::first();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('completes stage with AnyAssignee rule and advances to next stage', function () {
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

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch")
        ->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->where('sequence_order', 1)->first();

    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", [
            'completion_note' => 'Stage 1 completed successfully.',
        ])
        ->assertOk();

    expect($stageInstance->fresh()->status)->toBe(StageInstanceStatus::Completed);

    $stage2Instance = $task->fresh()->stageInstances()->where('sequence_order', 2)->first();
    expect($stage2Instance)->not->toBeNull();
    expect($stage2Instance->status)->toBe(StageInstanceStatus::Active);
});

it('completes stage with AllAssignees rule only when all required assignees complete', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee1 = User::factory()->create(['is_active' => true]);
    $assignee2 = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create([
        'user_id' => $assignee1->id,
        'position_id' => $position->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);
    UserPositionAssignment::create([
        'user_id' => $assignee2->id,
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
        'assignment_type' => AssignmentType::ManualAtLaunch,
        'completion_rule' => CompletionRule::AllAssignees,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch", [
            'manual_assignments' => [
                [
                    'blueprint_stage_id' => $stage1->public_id,
                    'user_ids' => [$assignee1->public_id, $assignee2->public_id],
                ],
            ],
        ])
        ->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    $this->actingAs($assignee1)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", [
            'completion_note' => 'Assignee 1 done.',
        ])
        ->assertOk();
    expect($stageInstance->fresh()->status)->toBe(StageInstanceStatus::Active);

    $this->actingAs($assignee2)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", [
            'completion_note' => 'Assignee 2 done.',
        ])
        ->assertOk();
    expect($stageInstance->fresh()->status)->toBe(StageInstanceStatus::Completed);
});

it('completes the task when final stage is completed', function () {
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

    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
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

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch")
        ->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", [
            'completion_note' => 'Final stage completed.',
        ])
        ->assertOk();

    expect($task->fresh()->status)->toBe(TaskStatus::Completed);
    expect($task->fresh()->completed_at)->not->toBeNull();
});

it('completes stage with LeadAssignee rule only when the lead assignee completes', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $nonLeadAssignee = User::factory()->create(['is_active' => true]);
    $leadAssignee = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create(['user_id' => $nonLeadAssignee->id, 'position_id' => $position->id, 'is_primary' => true, 'started_at' => now()]);
    UserPositionAssignment::create(['user_id' => $leadAssignee->id, 'position_id' => $position->id, 'is_primary' => true, 'started_at' => now()]);

    $blueprint = Blueprint::factory()->create(['category_id' => $this->category->id, 'created_by_user_id' => $this->user->id, 'is_active' => true]);
    $stage1 = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id, 'stage_type_id' => $this->stageType->id, 'sequence_order' => 1,
        'assignment_type' => AssignmentType::ManualAtLaunch, 'completion_rule' => CompletionRule::LeadAssignee,
    ]);

    $task = Task::factory()->create(['blueprint_id' => $blueprint->id, 'priority_id' => $this->priority->id, 'initiator_user_id' => $this->user->id, 'status' => TaskStatus::Draft]);
    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch", [
            'manual_assignments' => [['blueprint_stage_id' => $stage1->public_id, 'user_ids' => [$leadAssignee->public_id, $nonLeadAssignee->public_id]]],
        ])->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    // Set roles explicitly: lead=Lead, non-lead=Required
    $stageInstance->assignments()->whereHas('user', fn ($q) => $q->where('public_id', $leadAssignee->public_id))
        ->update(['assignment_role' => AssignmentRole::Lead->value]);
    $stageInstance->assignments()->whereHas('user', fn ($q) => $q->where('public_id', $nonLeadAssignee->public_id))
        ->update(['assignment_role' => AssignmentRole::Required->value]);

    // Non-lead assignee completes first — stage should NOT complete
    $this->actingAs($nonLeadAssignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", ['completion_note' => 'Non-lead done.'])
        ->assertOk();
    expect($stageInstance->fresh()->status)->toBe(StageInstanceStatus::Active);

    // Lead assignee completes — stage should complete
    $this->actingAs($leadAssignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", ['completion_note' => 'Lead done.'])
        ->assertOk();
    expect($stageInstance->fresh()->status)->toBe(StageInstanceStatus::Completed);
});

it('rejects stage completion when user is not an assignee', function () {
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

    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
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

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch")
        ->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    $nonAssignee = User::factory()->create(['is_active' => true]);
    $this->actingAs($nonAssignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", [
            'completion_note' => 'Should fail.',
        ])
        ->assertNotFound();
});

it('rejects stage completion when required sub-stages are incomplete', function () {
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

    $task = Task::factory()->create(['blueprint_id' => $blueprint->id, 'priority_id' => $this->priority->id, 'initiator_user_id' => $this->user->id, 'status' => TaskStatus::Draft]);
    $this->withHeaders($this->authHeaders)->postJson("/v1/tasks/{$task->public_id}/launch")->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", ['completion_note' => 'Skip sub-stage.'])
        ->assertUnprocessable();
});

it('rejects stage completion when task is not active', function () {
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

    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
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

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch")
        ->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/suspend", ['reason' => 'Test suspension']);

    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}/complete", [
            'completion_note' => 'Should fail.',
        ])
        ->assertUnprocessable();
});
