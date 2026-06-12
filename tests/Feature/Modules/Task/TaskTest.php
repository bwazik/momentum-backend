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
        'name_en' => 'Task Test',
        'name_ar' => 'اختبار',
        'slug' => 'task-test-'.uniqid(),
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
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('creates draft task from active blueprint', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks', [
            'blueprint_id' => $this->blueprint->public_id,
            'title_ar' => 'مهمة جديدة',
            'description_ar' => 'وصف المهمة',
        ]);

    $response->assertOk()
        ->assertJsonPath('title_ar', 'مهمة جديدة');

    $this->assertDatabaseHas('tasks', [
        'title_ar' => 'مهمة جديدة',
        'status' => TaskStatus::Draft->value,
    ]);
});

it('creates task with default priority when not provided', function () {
    $defaultPriority = TaskPriority::where('is_default', true)->first();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks', [
            'blueprint_id' => $this->blueprint->public_id,
            'title_ar' => 'مهمة بدون أولوية',
            'description_ar' => 'وصف',
        ]);

    $response->assertOk();

    $task = Task::where('title_ar', 'مهمة بدون أولوية')->first();
    expect($task->priority_id)->toBe($defaultPriority->id);
});

it('updates draft task', function () {
    $task = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/tasks/{$task->public_id}", [
            'title_ar' => 'عنوان محدث',
        ]);

    $response->assertOk()
        ->assertJsonPath('title_ar', 'عنوان محدث');
});

it('deletes draft task by initiator', function () {
    $task = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/tasks/{$task->public_id}");

    $response->assertStatus(204);
    expect(Task::where('public_id', $task->public_id)->exists())->toBeFalse();
});

it('lists tasks with cursor pagination', function () {
    Task::factory()->count(3)->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tasks');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'title_ar']]]);
});

it('shows task with stage instances', function () {
    $task = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$task->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $task->public_id);
});
