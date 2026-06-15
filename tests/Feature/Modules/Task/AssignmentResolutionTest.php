<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
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
        'name_en' => 'Assignment Test',
        'name_ar' => 'اختبار',
        'slug' => 'ar-test-'.uniqid(),
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
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('launches with specific position assignment when position occupied', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create([
        'department_id' => $department->id,
        'is_active' => true,
    ]);
    $occupant = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create([
        'user_id' => $occupant->id,
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
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'assignment_type' => AssignmentType::SpecificPosition,
        'assigned_position_id' => $position->id,
        'sequence_order' => 1,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch");

    $response->assertOk()
        ->assertJsonPath('status', TaskStatus::Active->value);

    expect($task->fresh()->status)->toBe(TaskStatus::Active);
});

it('launches with manual at launch assignment when users provided', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $stageType = StageType::first();
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'assignment_type' => AssignmentType::ManualAtLaunch,
        'sequence_order' => 1,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch", [
            'manual_assignments' => [
                [
                    'blueprint_stage_id' => $stage->public_id,
                    'user_ids' => [$this->user->public_id],
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('status', TaskStatus::Active->value);
});

it('launches without manual assignment returns 422', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $stageType = StageType::first();
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'assignment_type' => AssignmentType::ManualAtLaunch,
        'sequence_order' => 1,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch");

    $response->assertUnprocessable();
});

it('launch locks blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => true,
        'is_locked' => false,
    ]);

    $stageType = StageType::first();
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'assignment_type' => AssignmentType::ManualAtLaunch,
        'sequence_order' => 1,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch", [
            'manual_assignments' => [
                [
                    'blueprint_stage_id' => BlueprintStage::where('blueprint_id', $blueprint->id)->first()->public_id,
                    'user_ids' => [$this->user->public_id],
                ],
            ],
        ]);

    $response->assertOk();
    expect($blueprint->fresh()->is_locked)->toBeTrue();
});

it('returns 403 when non-initiator without task.manage tries to launch', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $stageType = StageType::first();
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'assignment_type' => AssignmentType::ManualAtLaunch,
        'sequence_order' => 1,
    ]);

    $otherUser = User::factory()->create(['is_active' => true]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $otherUser->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch", [
            'manual_assignments' => [
                [
                    'blueprint_stage_id' => BlueprintStage::where('blueprint_id', $blueprint->id)->first()->public_id,
                    'user_ids' => [$this->user->public_id],
                ],
            ],
        ]);

    $response->assertForbidden();
});

it('returns 422 when launching from inactive blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => false,
    ]);

    $stageType = StageType::first();
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'assignment_type' => AssignmentType::ManualAtLaunch,
        'sequence_order' => 1,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch", [
            'manual_assignments' => [
                [
                    'blueprint_stage_id' => BlueprintStage::where('blueprint_id', $blueprint->id)->first()->public_id,
                    'user_ids' => [$this->user->public_id],
                ],
            ],
        ]);

    $response->assertUnprocessable();
});

it('returns 422 when launching from blueprint with no stages', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $task = Task::factory()->create([
        'blueprint_id' => $blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/launch");

    $response->assertUnprocessable();
});
