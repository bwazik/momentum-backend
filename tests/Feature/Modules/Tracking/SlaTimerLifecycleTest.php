<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
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
        'name_en' => 'SLA Timer Lifecycle Test',
        'name_ar' => 'اختبار',
        'slug' => 'slatl-test-'.uniqid(),
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

    // Create blueprint with SLA stage
    $this->blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $stageType = StageType::first();
    $this->stage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $stageType->id,
        'assignment_type' => AssignmentType::ManualAtLaunch,
        'sla_policy_id' => $this->slaPolicy->id,
        'sequence_order' => 1,
        'completion_rule' => CompletionRule::AnyAssignee,
    ]);

    // Launch task → creates timer via event
    $this->task = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/launch", [
            'manual_assignments' => [
                [
                    'blueprint_stage_id' => $this->stage->public_id,
                    'user_ids' => [$this->user->public_id],
                ],
            ],
        ]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('pauses timers on task suspension', function () {
    expect(SlaTimerInstance::first()->status)->toBe(SlaTimerStatus::Running);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/suspend", [
            'reason' => 'Test suspension',
        ]);

    expect(SlaTimerInstance::first()->status)->toBe(SlaTimerStatus::Paused);
    expect(SlaTimerInstance::first()->paused_at)->not->toBeNull();
    expect(SlaTimerInstance::first()->elapsed_before_pause)->toBeGreaterThanOrEqual(0);
});

it('resumes timers on task resume', function () {
    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/suspend", [
            'reason' => 'Test suspension',
        ]);

    expect(SlaTimerInstance::first()->status)->toBe(SlaTimerStatus::Paused);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/resume");

    $timer = SlaTimerInstance::first();
    expect($timer->status)->not->toBe(SlaTimerStatus::Paused);
    expect($timer->paused_at)->toBeNull();
});

it('completes all timers on task cancel', function () {
    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/cancel", [
            'reason' => 'Test cancellation',
        ]);

    expect(SlaTimerInstance::first()->status)->toBe(SlaTimerStatus::Completed);
    expect(SlaTimerInstance::first()->completed_at)->not->toBeNull();
});
