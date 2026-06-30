<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
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
        'name_en' => 'Task Lifecycle Test',
        'name_ar' => 'اختبار',
        'slug' => 'tl-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->category = BlueprintCategory::factory()->create();
    $this->blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => User::factory()->create()->id,
    ]);
    $this->priority = TaskPriority::where('is_default', true)->first();

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

it('suspends active task with reason', function () {
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/suspend", [
            'reason' => 'Pending external input',
        ]);

    $response->assertOk()
        ->assertJsonPath('status', TaskStatus::Suspended->apiValue());
});

it('cannot suspend draft task', function () {
    $task = Task::factory()->draft()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/suspend", [
            'reason' => 'Test',
        ]);

    $response->assertUnprocessable();
});

it('resumes suspended task', function () {
    $task = Task::factory()->suspended()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/resume");

    $response->assertOk()
        ->assertJsonPath('status', TaskStatus::Active->apiValue());
});

it('cannot resume active task', function () {
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/resume");

    $response->assertUnprocessable();
});

it('cancels active task with reason', function () {
    $task = Task::factory()->active()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/cancel", [
            'reason' => 'No longer needed',
        ]);

    $response->assertOk()
        ->assertJsonPath('status', TaskStatus::Cancelled->apiValue());
});

it('cancels draft task with reason', function () {
    $task = Task::factory()->draft()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/cancel", [
            'reason' => 'Mistake',
        ]);

    $response->assertOk()
        ->assertJsonPath('status', TaskStatus::Cancelled->apiValue());
});

it('cannot cancel completed task', function () {
    $task = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$task->public_id}/cancel", [
            'reason' => 'Test',
        ]);

    $response->assertUnprocessable();
});
