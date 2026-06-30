<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Enums\SlaUnit;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\MonitoringScopeGrant;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Modules\Tracking\Models\SlaTimerInstance;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'FollowUp Board Test',
        'name_ar' => 'اختبار',
        'slug' => 'fub-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->department = Department::factory()->create(['is_active' => true]);
    $this->otherDepartment = Department::factory()->create(['is_active' => true]);
    $this->calendar = WorkingCalendar::factory()->create(['is_default' => true]);
    $this->slaPolicy = SlaPolicy::factory()->create([
        'sla_value' => 5,
        'sla_unit' => SlaUnit::Days,
    ]);

    $this->user = User::factory()->tenantAdmin()->create();
    $this->actingAs($this->user, 'sanctum');

    $orgCapability = Capability::where('key', 'task.view.organization')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $orgCapability->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

// --- Board Basics ---

it('returns empty board for user with no tasks visible', function () {
    $restrictedUser = User::factory()->create();
    $this->actingAs($restrictedUser, 'sanctum');

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(0, 'data');
});

it('returns board with tasks visible via organization scope', function () {
    $task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
    ]);

    $task->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now()->subHours(2),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'title_ar', 'status', 'current_stage', 'sla_health', 'time_at_current_stage_seconds']], 'next_cursor', 'has_more'])
        ->assertJsonCount(1, 'data');
});

it('excludes draft tasks from board', function () {
    Task::factory()->create(['initiator_user_id' => $this->user->id]);
    Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

// --- Filters ---

it('filters board by status active', function () {
    Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    Task::factory()->suspended()->create(['initiator_user_id' => $this->user->id]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?status=active');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters board by department', function () {
    $task1 = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $task1->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now(),
    ]);

    $task2 = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $task2->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->otherDepartment->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?department_id='.$this->department->public_id);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters board by single priority', function () {
    $priority1 = TaskPriority::factory()->create(['is_active' => true]);
    $priority2 = TaskPriority::factory()->create(['is_active' => true]);

    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority1->id,
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority2->id,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?priority_id[]='.$priority1->public_id);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters board by multiple priorities', function () {
    $priority1 = TaskPriority::factory()->create(['is_active' => true]);
    $priority2 = TaskPriority::factory()->create(['is_active' => true]);

    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority1->id,
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority2->id,
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority1->id,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?priority_id[]='.$priority1->public_id.'&priority_id[]='.$priority2->public_id);

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

it('filters board by search', function () {
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'title_ar' => 'مهمة مميزة جدا',
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'title_ar' => 'شيء آخر',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?search='.urlencode('مميزة'));

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters board by stage type', function () {
    $stageType = StageType::first();

    $task = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $blueprintStage = BlueprintStage::factory()->create(['stage_type_id' => $stageType->id]);
    $task->stageInstances()->create([
        'blueprint_stage_id' => $blueprintStage->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?stage_type_id='.$stageType->public_id);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters board by date range', function () {
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'created_at' => now()->subDays(5),
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'created_at' => now()->subDays(1),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?date_from='.now()->subDays(3)->toDateString());

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects external_reference filter with 422', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?external_reference=REF-001');

    $response->assertStatus(422);
});

// --- Sort ---

it('sorts board by priority ascending', function () {
    $critical = TaskPriority::where('severity_rank', 1)->first();
    $routine = TaskPriority::where('severity_rank', 3)->first();

    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $routine->id,
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $critical->id,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?sort_by=priority&sort_direction=asc');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('defaults to longest-waiting first (time_at_stage desc)', function () {
    $taskOld = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $taskOld->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now()->subDays(3),
    ]);

    $taskNew = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $taskNew->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now()->subHour(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.public_id', $taskOld->public_id);
});

// --- Overdue & At-Risk ---

it('returns overdue list sorted by breach age', function () {
    $stageType = StageType::first();
    $bpStageOld = BlueprintStage::factory()->create(['stage_type_id' => $stageType->id]);
    $bpStageNew = BlueprintStage::factory()->create(['stage_type_id' => $stageType->id]);

    $taskOld = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $stageOld = $taskOld->stageInstances()->create([
        'blueprint_stage_id' => $bpStageOld->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now()->subDays(3),
    ]);
    SlaTimerInstance::factory()->breached()->create([
        'task_id' => $taskOld->id,
        'stage_instance_id' => $stageOld->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
    ]);

    $taskNew = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $stageNew = $taskNew->stageInstances()->create([
        'blueprint_stage_id' => $bpStageNew->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now()->subDay(),
    ]);
    SlaTimerInstance::factory()->breached()->create([
        'task_id' => $taskNew->id,
        'stage_instance_id' => $stageNew->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/overdue');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.public_id', $taskOld->public_id);
});

it('returns at-risk list sorted by soonest deadline', function () {
    $bpStage1 = BlueprintStage::factory()->create();
    $bpStage2 = BlueprintStage::factory()->create();

    $taskEarly = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $stageEarly = $taskEarly->stageInstances()->create([
        'blueprint_stage_id' => $bpStage1->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now()->subDays(2),
    ]);
    SlaTimerInstance::factory()->warning()->create([
        'task_id' => $taskEarly->id,
        'stage_instance_id' => $stageEarly->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'deadline_at' => now()->addHour(),
    ]);

    $taskLate = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $stageLate = $taskLate->stageInstances()->create([
        'blueprint_stage_id' => $bpStage2->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now()->subDay(),
    ]);
    SlaTimerInstance::factory()->warning()->create([
        'task_id' => $taskLate->id,
        'stage_instance_id' => $stageLate->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'deadline_at' => now()->addDay(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/at-risk');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.public_id', $taskEarly->public_id);
});

it('overdue list excludes non-active tasks', function () {
    $task = Task::factory()->create([
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Completed,
        'completed_at' => now(),
    ]);
    $stage = $task->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Completed,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now()->subDays(2),
        'exited_at' => now(),
    ]);

    SlaTimerInstance::factory()->breached()->create([
        'task_id' => $task->id,
        'stage_instance_id' => $stage->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/overdue');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

// --- Bottlenecks ---

it('returns bottlenecks endpoint', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/bottlenecks');

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

it('returns bottleneck counts accurately', function () {
    $stageType = StageType::first();
    $bpStage = BlueprintStage::factory()->create(['stage_type_id' => $stageType->id]);

    for ($i = 0; $i < 3; $i++) {
        $task = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
        $stage = $task->stageInstances()->create([
            'blueprint_stage_id' => $bpStage->id,
            'sequence_order' => 1,
            'owning_department_id' => $this->department->id,
            'status' => StageInstanceStatus::Active,
            'completion_rule' => CompletionRule::AnyAssignee->value,
            'entered_at' => now()->subDays(1 + $i),
        ]);
        SlaTimerInstance::factory()->breached()->create([
            'task_id' => $task->id,
            'stage_instance_id' => $stage->id,
            'sla_policy_id' => $this->slaPolicy->id,
            'working_calendar_id' => $this->calendar->id,
        ]);
    }

    for ($i = 0; $i < 2; $i++) {
        $task = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
        $stage = $task->stageInstances()->create([
            'blueprint_stage_id' => $bpStage->id,
            'sequence_order' => 1,
            'owning_department_id' => $this->department->id,
            'status' => StageInstanceStatus::Active,
            'completion_rule' => CompletionRule::AnyAssignee->value,
            'entered_at' => now()->subHours(6),
        ]);
        SlaTimerInstance::factory()->warning()->create([
            'task_id' => $task->id,
            'stage_instance_id' => $stage->id,
            'sla_policy_id' => $this->slaPolicy->id,
            'working_calendar_id' => $this->calendar->id,
        ]);
    }

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/bottlenecks');

    $response->assertOk()
        ->assertJsonStructure(['data'])
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.overdue_count', 3)
        ->assertJsonPath('data.0.at_risk_count', 2)
        ->assertJsonPath('data.0.score', 8);
});

it('denies bottlenecks without capability', function () {
    $restrictedUser = User::factory()->create();
    $this->actingAs($restrictedUser, 'sanctum');

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/bottlenecks');

    $response->assertStatus(422);
});

// --- Monitoring Scope Visibility ---

it('respects follow_up_scope visibility', function () {
    $followUpUser = User::factory()->create();
    $followUpCap = Capability::where('key', 'task.view.follow_up_scope')->first();
    UserCapabilityGrant::create([
        'user_id' => $followUpUser->id,
        'capability_id' => $followUpCap->id,
        'scope_type' => ScopeType::SPECIFIC_DEPARTMENT,
        'scope_department_id' => $this->department->id,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test',
    ]);

    MonitoringScopeGrant::create([
        'user_id' => $followUpUser->id,
        'scope_type' => ScopeType::SPECIFIC_DEPARTMENT,
        'scope_department_id' => $this->department->id,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test',
    ]);

    $taskInScope = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $taskInScope->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now(),
    ]);

    $this->actingAs($followUpUser, 'sanctum');
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

// --- Confidential ---

it('hides confidential tasks without override capability', function () {
    $confidentialTask = Task::factory()->active()->create([
        'initiator_user_id' => User::factory()->create()->id,
        'classification_level' => ClassificationLevel::Confidential,
    ]);
    $confidentialTask->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

// --- Pagination ---

it('enforces cursor pagination contract', function () {
    Task::factory()->active()->count(3)->create(['initiator_user_id' => $this->user->id]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/board?per_page=1');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});
