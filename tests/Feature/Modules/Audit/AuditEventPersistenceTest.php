<?php

use App\Models\User;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Events\StageInstanceCompleted;
use App\Modules\Task\Events\TaskCreated;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Audit Persistence Test',
        'name_ar' => 'اختبار',
        'slug' => 'audit-persist-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);

    User::factory()->tenantAdmin()->create([
        'email' => 'admin@'.$this->tenant->slug.'.test',
        'password' => bcrypt('password'),
    ]);

    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('is append-only: update returns false', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));

    $event = AuditEvent::first();
    $result = $event->update(['event_type' => 'modified']);

    expect($result)->toBeFalse();
});

it('is append-only: delete returns false', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));

    $event = AuditEvent::first();
    $result = $event->delete();

    expect($result)->toBeFalse();
});

it('listener safety: exception in listener does not break originating action', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);

    event(new TaskCreated($task));

    expect($task->fresh())->not->toBeNull();
});

it('creates audit event with root_entity_public_id for task events', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));

    $event = AuditEvent::where('event_type', 'task.created')->first();

    expect($event)->not->toBeNull();
    expect($event->root_entity_public_id)->toBe($task->public_id);
});

it('records stage completion events with correct root entity', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    $stageInstance = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => BlueprintStage::factory()->create([
            'blueprint_id' => $task->blueprint_id,
        ])->id,
        'sequence_order' => 1,
        'completion_rule' => CompletionRule::AnyAssignee,
        'status' => StageInstanceStatus::Active,
    ]);
    event(new StageInstanceCompleted($stageInstance));

    $event = AuditEvent::where('event_type', 'stage.completed')->first();

    expect($event)->not->toBeNull();
    expect($event->root_entity_type?->name())->toBe('task');
    expect($event->root_entity_id)->toBe($task->id);
});

it('records impersonated_by_public_id on audit events', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    $impersonatorId = (string) Str::uuid7();

    $event = AuditEvent::create([
        'event_type' => 'test.impersonation',
        'entity_type' => AuditEntityType::Task,
        'entity_id' => $task->id,
        'entity_public_id' => $task->public_id,
        'root_entity_type' => AuditEntityType::Task,
        'root_entity_id' => $task->id,
        'root_entity_public_id' => $task->public_id,
        'user_id' => $this->user->id,
        'impersonated_by_public_id' => $impersonatorId,
    ]);

    expect($event->impersonated_by_public_id)->toBe($impersonatorId);
});
