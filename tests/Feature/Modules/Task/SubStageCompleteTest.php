<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
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
        'name_en' => 'Sub-stage Complete Test',
        'name_ar' => 'اختبار',
        'slug' => 'ssc-test-'.uniqid(),
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

it('completes sub-stage and activates next sub-stage', function () {
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

    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AllAssignees,
    ]);

    BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $stage->id,
        'sequence_order' => 1,
        'is_required' => true,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);
    BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $stage->id,
        'sequence_order' => 2,
        'is_required' => true,
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
    $subStage1Instance = $stageInstance->subStageInstances()->where('sequence_order', 1)->first();
    $subStage2Instance = $stageInstance->subStageInstances()->where('sequence_order', 2)->first();

    expect($subStage1Instance->status)->toBe(SubStageInstanceStatus::Active);
    expect($subStage2Instance->status)->toBe(SubStageInstanceStatus::Pending);

    $this->actingAs($assignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/sub-stages/{$subStage1Instance->id}/complete", [
            'completion_note' => 'Sub-stage 1 done.',
        ])
        ->assertOk();

    expect($subStage1Instance->fresh()->status)->toBe(SubStageInstanceStatus::Completed);

    $activeSubStage2 = $stageInstance->fresh()->subStageInstances()
        ->where('sequence_order', 2)
        ->where('status', SubStageInstanceStatus::Active->value)
        ->first();
    expect($activeSubStage2)->not->toBeNull();
    expect($activeSubStage2->status)->toBe(SubStageInstanceStatus::Active);
});

it('rejects sub-stage completion when user is not an assignee', function () {
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

    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $this->stageType->id,
        'sequence_order' => 1,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $stage->id,
        'sequence_order' => 1,
        'is_required' => true,
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

    $subStageInstance = $task->fresh()->stageInstances()->first()
        ->subStageInstances()->where('sequence_order', 1)->first();

    $nonAssignee = User::factory()->create(['is_active' => true]);
    $this->actingAs($nonAssignee)->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson("/v1/tasks/{$task->public_id}/sub-stages/{$subStageInstance->id}/complete", [
            'completion_note' => 'Should fail.',
        ])
        ->assertNotFound();
});
