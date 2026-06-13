<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\SlaUnit;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use App\Modules\Tracking\Models\SlaTimerInstance;
use App\Modules\Tracking\Services\SlaThresholdService;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Threshold Test',
        'name_ar' => 'اختبار',
        'slug' => 'th-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->category = BlueprintCategory::factory()->create();
    $this->user = User::factory()->create(['is_active' => true]);
    $this->calendar = WorkingCalendar::factory()->create(['is_default' => true]);
    $this->slaPolicy = SlaPolicy::factory()->create([
        'sla_value' => 2,
        'sla_unit' => SlaUnit::Hours,
        'warning_threshold_percentage' => 50,
    ]);

    $this->task = Task::factory()->active()->create();
    $this->blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $stageType = StageType::first();
    $this->blueprintStage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $stageType->id,
    ]);
    $this->stageInstance = TaskStageInstance::create([
        'task_id' => $this->task->id,
        'blueprint_stage_id' => $this->blueprintStage->id,
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

it('transitions to warning when warning_at is past', function () {
    $timer = SlaTimerInstance::factory()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $this->stageInstance->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'status' => SlaTimerStatus::Running,
        'started_at' => now()->subHours(2),
        'warning_at' => now()->subMinute(),
        'deadline_at' => now()->addHour(),
    ]);

    app(SlaThresholdService::class)->processWarnings();

    expect($timer->fresh()->status)->toBe(SlaTimerStatus::Warning);
});

it('transitions to breached when deadline_at is past', function () {
    $timer = SlaTimerInstance::factory()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $this->stageInstance->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'status' => SlaTimerStatus::Warning,
        'started_at' => now()->subHours(3),
        'warning_at' => now()->subHour(),
        'deadline_at' => now()->subMinute(),
    ]);

    app(SlaThresholdService::class)->processBreaches();

    expect($timer->fresh()->status)->toBe(SlaTimerStatus::Breached);
});

it('does not double-warn or double-breach', function () {
    $timer = SlaTimerInstance::factory()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $this->stageInstance->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'status' => SlaTimerStatus::Running,
        'started_at' => now()->subHours(2),
        'warning_at' => now()->subMinute(),
        'deadline_at' => now()->subMinute(),
    ]);

    app(SlaThresholdService::class)->processWarnings();
    app(SlaThresholdService::class)->processWarnings();
    app(SlaThresholdService::class)->processBreaches();
    app(SlaThresholdService::class)->processBreaches();

    expect($timer->fresh()->status)->toBe(SlaTimerStatus::Breached);
});
