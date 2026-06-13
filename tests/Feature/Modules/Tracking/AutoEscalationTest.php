<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\SlaUnit;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Enums\AssignmentRole;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Tracking\Enums\EscalationStatus;
use App\Modules\Tracking\Enums\EscalationType;
use App\Modules\Tracking\Models\Escalation;
use App\Modules\Tracking\Models\SlaTimerInstance;
use App\Modules\Tracking\Services\SlaEscalationService;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Auto Escalation Test',
        'name_ar' => 'اختبار',
        'slug' => 'ae-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->calendar = WorkingCalendar::factory()->create(['is_default' => true]);
    $this->slaPolicy = SlaPolicy::factory()->create([
        'sla_value' => 2,
        'sla_unit' => SlaUnit::Hours,
        'warning_threshold_percentage' => 50,
    ]);

    $this->department = Department::factory()->create();
    $this->managerPosition = Position::factory()->create([
        'department_id' => $this->department->id,
        'is_active' => true,
    ]);
    $this->manager = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create([
        'user_id' => $this->manager->id,
        'position_id' => $this->managerPosition->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);

    $this->assigneePosition = Position::factory()->create([
        'department_id' => $this->department->id,
        'reports_to_position_id' => $this->managerPosition->id,
        'is_active' => true,
    ]);
    $this->assignee = User::factory()->create(['is_active' => true]);
    UserPositionAssignment::create([
        'user_id' => $this->assignee->id,
        'position_id' => $this->assigneePosition->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);

    $this->task = Task::factory()->active()->create();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('resolves escalation target from blueprint override position', function () {
    $blueprintStage = BlueprintStage::factory()->create([
        'escalation_position_id' => $this->managerPosition->id,
    ]);

    $stageInstance = TaskStageInstance::create([
        'task_id' => $this->task->id,
        'blueprint_stage_id' => $blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
        'status' => StageInstanceStatus::Active,
        'entered_at' => now(),
    ]);

    TaskStageAssignment::create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $stageInstance->id,
        'user_id' => $this->assignee->id,
        'position_id' => $this->assigneePosition->id,
        'assignment_role' => AssignmentRole::Required,
        'is_completed' => false,
        'assigned_at' => now(),
    ]);

    $timer = SlaTimerInstance::factory()->warning()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $stageInstance->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'warning_at' => now()->subHour(),
        'deadline_at' => now()->subMinute(),
    ]);

    // Verify relationships work before escalation
    $freshTimer = $timer->fresh();
    expect($freshTimer->stageInstance)->not->toBeNull();
    expect($freshTimer->stageInstance->blueprintStage)->not->toBeNull();
    expect($freshTimer->stageInstance->blueprintStage->escalation_position_id)->toBe($this->managerPosition->id);

    // Direct test: check the escalation target resolution
    $blueprintStage = $freshTimer->stageInstance->blueprintStage;
    if ($blueprintStage->escalation_position_id) {
        $position = Position::find($blueprintStage->escalation_position_id);
        expect($position)->not->toBeNull();
        $occupant = $position->currentOccupant;
        expect($occupant)->not->toBeNull('Manager position should have an occupant');
    }

    app(SlaEscalationService::class)->createAutoEscalation($freshTimer);

    $escalations = Escalation::all();
    expect($escalations)->toHaveCount(1);
    expect($escalations->first()->escalation_type)->toBe(EscalationType::AutoSlaBreach);
    expect($escalations->first()->status)->toBe(EscalationStatus::Open);
    expect($escalations->first()->escalated_to_user_id)->toBe($this->manager->id);
});

it('resolves escalation target from assignee reporting line', function () {
    $blueprintStage = BlueprintStage::factory()->create([
        'escalation_position_id' => null,
    ]);

    $stageInstance = TaskStageInstance::create([
        'task_id' => $this->task->id,
        'blueprint_stage_id' => $blueprintStage->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
        'status' => StageInstanceStatus::Active,
        'entered_at' => now(),
    ]);

    TaskStageAssignment::create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $stageInstance->id,
        'user_id' => $this->assignee->id,
        'position_id' => $this->assigneePosition->id,
        'assignment_role' => AssignmentRole::Required,
        'is_completed' => false,
        'assigned_at' => now(),
    ]);

    $timer = SlaTimerInstance::factory()->warning()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $stageInstance->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'warning_at' => now()->subHour(),
        'deadline_at' => now()->subMinute(),
    ]);

    app(SlaEscalationService::class)->createAutoEscalation($timer->fresh());

    $escalations = Escalation::where('sla_timer_instance_id', $timer->id)->get();
    expect($escalations)->toHaveCount(1);
    expect($escalations->first()->escalated_to_user_id)->toBe($this->manager->id);
});
