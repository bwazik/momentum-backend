<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Events\StageAssignmentCreated;
use App\Modules\Task\Events\StageInstanceAdvanced;
use App\Modules\Task\Events\StageInstanceReturned;
use App\Modules\Task\Events\TaskCancelled;
use App\Modules\Task\Events\TaskCompleted;
use App\Modules\Task\Events\TaskResumed;
use App\Modules\Task\Events\TaskSuspended;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Tracking\Events\EscalationCreated;
use App\Modules\Tracking\Events\SlaBreached;
use App\Modules\Tracking\Events\SlaWarningTriggered;
use App\Modules\Tracking\Models\Escalation;
use App\Modules\Tracking\Models\SlaTimerInstance;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Notification Delivery Test',
        'name_ar' => 'اختبار',
        'slug' => 'notif-delivery-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->category = BlueprintCategory::factory()->create();
    $this->blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => User::factory()->create()->id,
    ]);
    $this->blueprintStage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'sequence_order' => 1,
    ]);
    $this->priority = TaskPriority::where('is_default', true)->first();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('creates notification on StageAssignmentCreated', function () {
    $user = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $user->id,
    ]);
    $stageInstance = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
    ]);
    $assignment = TaskStageAssignment::create([
        'task_id' => $task->id,
        'stage_instance_id' => $stageInstance->id,
        'user_id' => $user->id,
        'is_completed' => false,
        'assigned_at' => now(),
    ]);
    $stageInstance->setRelation('task', $task);
    $stageInstance->setRelation('blueprintStage', $this->blueprintStage);
    $assignment->setRelation('user', $user);
    $assignment->setRelation('stageInstance', $stageInstance);

    event(new StageAssignmentCreated($assignment));

    expect($user->notifications()->count())->toBe(1);
    expect($user->notifications()->first()->data['notification_type'])->toBe('stage_assignment_received');
});

it('is idempotent on replayed StageAssignmentCreated', function () {
    $user = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $user->id,
    ]);
    $stageInstance = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
    ]);
    $assignment = TaskStageAssignment::create([
        'task_id' => $task->id,
        'stage_instance_id' => $stageInstance->id,
        'user_id' => $user->id,
        'is_completed' => false,
        'assigned_at' => now(),
    ]);
    $stageInstance->setRelation('task', $task);
    $stageInstance->setRelation('blueprintStage', $this->blueprintStage);
    $assignment->setRelation('user', $user);
    $assignment->setRelation('stageInstance', $stageInstance);

    event(new StageAssignmentCreated($assignment));
    event(new StageAssignmentCreated($assignment));

    expect($user->notifications()->count())->toBe(1);
});

it('creates notification on TaskCompleted', function () {
    $initiator = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $initiator->id,
    ]);
    $task->setRelation('initiator', $initiator);

    event(new TaskCompleted($task));

    expect($initiator->notifications()->count())->toBe(1);
    expect($initiator->notifications()->first()->data['notification_type'])->toBe('task_completed');
});

it('creates notification on TaskCancelled', function () {
    $initiator = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $initiator->id,
    ]);
    $task->setRelation('initiator', $initiator);

    event(new TaskCancelled($task, 'Test cancellation reason'));

    expect($initiator->notifications()->count())->toBe(1);
    expect($initiator->notifications()->first()->data['notification_type'])->toBe('task_cancelled');
});

it('creates notification on TaskSuspended', function () {
    $initiator = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $initiator->id,
    ]);
    $task->setRelation('initiator', $initiator);

    event(new TaskSuspended($task, 'Test suspension reason'));

    expect($initiator->notifications()->count())->toBe(1);
    expect($initiator->notifications()->first()->data['notification_type'])->toBe('task_suspended');
});

it('creates notification on TaskResumed', function () {
    $initiator = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $initiator->id,
    ]);
    $task->setRelation('initiator', $initiator);

    event(new TaskResumed($task));

    expect($initiator->notifications()->count())->toBe(1);
    expect($initiator->notifications()->first()->data['notification_type'])->toBe('task_resumed');
});

it('creates notification on EscalationCreated', function () {
    $targetUser = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => User::factory()->create()->id,
    ]);
    $stageInstance = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
    ]);
    $escalation = Escalation::factory()->create([
        'task_id' => $task->id,
        'stage_instance_id' => $stageInstance->id,
        'escalated_to_user_id' => $targetUser->id,
    ]);
    $escalation->setRelation('escalatedToUser', $targetUser);
    $escalation->setRelation('task', $task);
    $stageInstance->setRelation('blueprintStage', $this->blueprintStage);
    $escalation->setRelation('stageInstance', $stageInstance);

    event(new EscalationCreated($escalation));

    expect($targetUser->notifications()->count())->toBe(1);
    expect($targetUser->notifications()->first()->data['notification_type'])->toBe('escalation_received');
});

it('skips inactive user on StageAssignmentCreated', function () {
    $user = User::factory()->create(['is_active' => false]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $user->id,
    ]);
    $stageInstance = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
    ]);
    $assignment = TaskStageAssignment::create([
        'task_id' => $task->id,
        'stage_instance_id' => $stageInstance->id,
        'user_id' => $user->id,
        'is_completed' => false,
        'assigned_at' => now(),
    ]);
    $stageInstance->setRelation('task', $task);
    $stageInstance->setRelation('blueprintStage', $this->blueprintStage);
    $assignment->setRelation('user', $user);
    $assignment->setRelation('stageInstance', $stageInstance);

    event(new StageAssignmentCreated($assignment));

    expect($user->notifications()->count())->toBe(0);
});

it('creates notification on SlaWarningTriggered via timer stage_instance_id', function () {
    $user = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => User::factory()->create()->id,
    ]);
    $stageInstance = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
    ]);
    TaskStageAssignment::create([
        'task_id' => $task->id,
        'stage_instance_id' => $stageInstance->id,
        'user_id' => $user->id,
        'is_completed' => false,
        'assigned_at' => now(),
    ]);
    $slaPolicy = SlaPolicy::factory()->create();
    $calendar = WorkingCalendar::factory()->default()->create();
    $timer = SlaTimerInstance::factory()->create([
        'task_id' => $task->id,
        'stage_instance_id' => $stageInstance->id,
        'sla_policy_id' => $slaPolicy->id,
        'working_calendar_id' => $calendar->id,
        'public_id' => (string) str()->uuid7(),
    ]);

    event(new SlaWarningTriggered($timer));

    expect($user->notifications()->count())->toBe(1);
    expect($user->notifications()->first()->data['notification_type'])->toBe('sla_warning');
});

it('creates notification on SlaBreached via timer stage_instance_id', function () {
    $user = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => User::factory()->create()->id,
    ]);
    $stageInstance = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
    ]);
    TaskStageAssignment::create([
        'task_id' => $task->id,
        'stage_instance_id' => $stageInstance->id,
        'user_id' => $user->id,
        'is_completed' => false,
        'assigned_at' => now(),
    ]);
    $slaPolicy = SlaPolicy::factory()->create();
    $calendar = WorkingCalendar::factory()->default()->create();
    $timer = SlaTimerInstance::factory()->create([
        'task_id' => $task->id,
        'stage_instance_id' => $stageInstance->id,
        'sla_policy_id' => $slaPolicy->id,
        'working_calendar_id' => $calendar->id,
        'public_id' => (string) str()->uuid7(),
    ]);

    event(new SlaBreached($timer));

    expect($user->notifications()->count())->toBe(1);
    expect($user->notifications()->first()->data['notification_type'])->toBe('sla_breach');
});

it('creates notification on StageInstanceAdvanced for completed assignees', function () {
    $user = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => User::factory()->create()->id,
    ]);
    $completedStage = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
        'status' => StageInstanceStatus::Completed,
    ]);
    TaskStageAssignment::create([
        'task_id' => $task->id,
        'stage_instance_id' => $completedStage->id,
        'user_id' => $user->id,
        'is_completed' => true,
        'assigned_at' => now(),
        'completed_at' => now(),
    ]);
    $newStage = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 2,
        'completion_rule' => 1,
    ]);
    $completedStage->setRelation('task', $task);
    $completedStage->setRelation('blueprintStage', $this->blueprintStage);

    event(new StageInstanceAdvanced($completedStage, $newStage));

    expect($user->notifications()->count())->toBe(1);
    expect($user->notifications()->first()->data['notification_type'])->toBe('task_advanced');
});

it('creates notification on StageInstanceReturned for return-target assignees', function () {
    $user = User::factory()->create(['is_active' => true]);
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => User::factory()->create()->id,
    ]);
    $returnedStage = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
        'status' => StageInstanceStatus::Returned,
    ]);
    $activeStage = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
        'sequence_order' => 2,
        'completion_rule' => 1,
        'status' => StageInstanceStatus::Active,
    ]);
    TaskStageAssignment::create([
        'task_id' => $task->id,
        'stage_instance_id' => $activeStage->id,
        'user_id' => $user->id,
        'is_completed' => false,
        'assigned_at' => now(),
    ]);

    event(new StageInstanceReturned($returnedStage, 'Return reason', $user));

    expect($user->notifications()->count())->toBe(1);
    expect($user->notifications()->first()->data['notification_type'])->toBe('task_returned');
});
