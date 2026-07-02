<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskConfidentialParticipant;
use App\Modules\Task\Models\TaskPriority;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Conf Visibility Test',
        'name_ar' => 'اختبار',
        'slug' => 'conf-vis-'.uniqid(),
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

    $this->department = Department::factory()->create();
    $this->position = Position::factory()->create(['department_id' => $this->department->id]);

    $this->initiator = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
    $this->orgViewUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->initiator->email,
            'password' => 'password',
        ]);
    $this->token = $loginResponse->json('token');
    $this->authHeaders = [
        'Authorization' => "Bearer {$this->token}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->task = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->initiator->id,
        'classification_level' => ClassificationLevel::Confidential,
        'status' => TaskStatus::Active,
        'launched_at' => now(),
    ]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('confidential task visible to initiator', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tasks');

    $response->assertOk();
    $taskIds = collect($response->json('data'))->pluck('public_id');
    expect($taskIds)->toContain($this->task->public_id);
});

it('confidential task hidden from org-wide viewer without participant status', function () {
    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->orgViewUser->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson('/v1/tasks');

    $response->assertOk();
    $taskIds = collect($response->json('data'))->pluck('public_id');
    expect($taskIds)->not->toContain($this->task->public_id);
});

it('confidential task visible to named participant', function () {
    TaskConfidentialParticipant::create([
        'task_id' => $this->task->id,
        'user_id' => $this->orgViewUser->id,
        'added_by_user_id' => $this->initiator->id,
        'added_at' => now(),
    ]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->orgViewUser->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson('/v1/tasks');

    $response->assertOk();
    $taskIds = collect($response->json('data'))->pluck('public_id');
    expect($taskIds)->toContain($this->task->public_id);
});

it('tenant admin sees confidential task', function () {
    // $this->initiator is already tenantAdmin
    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $this->task->public_id);
});

it('public task visible to all', function () {
    $publicTask = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->initiator->id,
        'classification_level' => ClassificationLevel::Public,
        'status' => TaskStatus::Active,
        'launched_at' => now(),
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tasks');

    $response->assertOk();
    $taskIds = collect($response->json('data'))->pluck('public_id');
    expect($taskIds)->toContain($publicTask->public_id);
});
