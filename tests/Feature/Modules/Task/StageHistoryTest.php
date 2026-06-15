<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Enums\TransitionType;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintTransition;
use App\Modules\Blueprint\Models\StageType;
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
        'name_en' => 'Stage History Test',
        'name_ar' => 'اختبار',
        'slug' => 'sh-test-'.uniqid(),
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
    $this->tenant->delete();
});

it('lists stage history for a task', function () {
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

    $stage1Instance = $task->fresh()->stageInstances()->where('sequence_order', 1)->first();
    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/stages/{$stage1Instance->id}/complete");

    $historyResponse = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$task->public_id}/stages");
    $historyResponse->assertOk();
    expect($historyResponse->json())->toHaveCount(2);
});

it('returns timeline for a launched task', function () {
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

    $timelineResponse = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$task->public_id}/timeline");
    $timelineResponse->assertOk();
    expect($timelineResponse->json())->toBeArray();
});

it('shows single stage instance with full details', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id, 'is_active' => true]);
    $assignee = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create(['user_id' => $assignee->id, 'position_id' => $position->id, 'is_primary' => true, 'started_at' => now()]);

    $blueprint = Blueprint::factory()->create(['category_id' => $this->category->id, 'created_by_user_id' => $this->user->id, 'is_active' => true]);
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id, 'stage_type_id' => $this->stageType->id, 'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition, 'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    $task = Task::factory()->create(['blueprint_id' => $blueprint->id, 'priority_id' => $this->priority->id, 'initiator_user_id' => $this->user->id, 'status' => TaskStatus::Draft]);
    $this->withHeaders($this->authHeaders)->postJson("/v1/tasks/{$task->public_id}/launch")->assertOk();

    $stageInstance = $task->fresh()->stageInstances()->first();

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$task->public_id}/stages/{$stageInstance->id}");
    $response->assertOk();
    $response->assertJsonStructure([
        'blueprint_stage' => ['public_id', 'name_ar', 'name_en'],
        'sequence_order', 'status', 'assignments',
    ]);
});

it('lists returns for a task', function () {
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
            'reason' => 'Need revisions.',
        ]);

    $returnsResponse = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$task->public_id}/returns");
    $returnsResponse->assertOk();
    expect($returnsResponse->json())->toHaveCount(1);
    expect($returnsResponse->json('0.return_reason'))->toBe('Need revisions.');
});
