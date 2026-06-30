<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\SlaUnit;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
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
        'name_en' => 'SLA Timer Creation Test',
        'name_ar' => 'اختبار',
        'slug' => 'slatc-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->category = BlueprintCategory::factory()->create();
    $this->priority = TaskPriority::where('is_default', true)->first();
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
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('creates SLA timer on stage entry when stage has sla policy', function () {
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
        'sla_policy_id' => $this->slaPolicy->id,
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

    $response->assertOk();

    expect(SlaTimerInstance::count())->toBe(1);
    expect(SlaTimerInstance::first()->status)->toBe(SlaTimerStatus::Running);
    expect(SlaTimerInstance::first()->sla_policy_id)->toBe($this->slaPolicy->id);
    expect(SlaTimerInstance::first()->deadline_at)->not->toBeNull();
    expect(SlaTimerInstance::first()->warning_at)->not->toBeNull();
});

it('does not create timer for stage without sla policy', function () {
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
        'sla_policy_id' => null,
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

    $response->assertOk();

    expect(SlaTimerInstance::count())->toBe(0);
});
