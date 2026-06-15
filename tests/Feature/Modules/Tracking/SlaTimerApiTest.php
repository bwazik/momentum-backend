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
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'SLA Timer API Test',
        'name_ar' => 'اختبار',
        'slug' => 'slapi-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->calendar = WorkingCalendar::factory()->create(['is_default' => true]);
    $this->slaPolicy = SlaPolicy::factory()->create([
        'sla_value' => 5,
        'sla_unit' => SlaUnit::Days,
    ]);

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->task = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $category = BlueprintCategory::factory()->create();
    $blueprint = Blueprint::factory()->create([
        'category_id' => $category->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $stageType = StageType::first();
    $blueprintStage = BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
    ]);
    $this->stageInstance = TaskStageInstance::create([
        'task_id' => $this->task->id,
        'blueprint_stage_id' => $blueprintStage->id,
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

it('shows task SLA health', function () {
    SlaTimerInstance::factory()->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $this->stageInstance->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'status' => SlaTimerStatus::Running,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tracking/sla/tasks/{$this->task->public_id}");

    $response->assertOk()
        ->assertJsonStructure([
            'task_id', 'overall_health', 'timers' => [
                '*' => ['public_id', 'status', 'started_at', 'deadline_at'],
            ],
        ]);
});

it('lists SLA timers with cursor pagination', function () {
    SlaTimerInstance::factory()->count(5)->create([
        'task_id' => $this->task->id,
        'stage_instance_id' => $this->stageInstance->id,
        'sla_policy_id' => $this->slaPolicy->id,
        'working_calendar_id' => $this->calendar->id,
        'status' => SlaTimerStatus::Running,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tracking/sla/timers?per_page=3');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});
