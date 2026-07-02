<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
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
        'name_en' => 'Conf Participant Test',
        'name_ar' => 'اختبار',
        'slug' => 'conf-participant-'.uniqid(),
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

    $this->initiator = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
    $this->user = User::factory()->create(['password' => bcrypt('password')]);

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

it('adds a confidential participant', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/confidential-participants", [
            'user_id' => $this->user->public_id,
        ]);

    $response->assertCreated()
        ->assertJsonPath('user.public_id', $this->user->public_id);

    $this->assertDatabaseHas('task_confidential_participants', [
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'removed_at' => null,
    ]);

    $this->assertDatabaseHas('confidential_access_events', [
        'task_id' => $this->task->id,
        'user_id' => $this->initiator->id,
        'access_type' => 3,
    ]);
});

it('rejects duplicate participant', function () {
    TaskConfidentialParticipant::create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'added_by_user_id' => $this->initiator->id,
        'added_at' => now(),
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/confidential-participants", [
            'user_id' => $this->user->public_id,
        ]);

    $response->assertStatus(422);
});

it('removes a confidential participant', function () {
    TaskConfidentialParticipant::create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'added_by_user_id' => $this->initiator->id,
        'added_at' => now(),
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/tasks/{$this->task->public_id}/confidential-participants/{$this->user->public_id}");

    $response->assertStatus(204);

    $this->assertDatabaseHas('task_confidential_participants', [
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
    ]);

    $participant = TaskConfidentialParticipant::where('task_id', $this->task->id)
        ->where('user_id', $this->user->id)
        ->first();
    expect($participant->removed_at)->not->toBeNull();
});

it('lists confidential participants with cursor pagination', function () {
    TaskConfidentialParticipant::create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'added_by_user_id' => $this->initiator->id,
        'added_at' => now(),
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/confidential-participants");

    $response->assertOk()
        ->assertJsonStructure(['data' => [['user', 'added_by', 'added_at']], 'next_cursor', 'has_more']);
});

it('denies non-initiator without visibility', function () {
    $otherUser = User::factory()->create(['password' => bcrypt('password')]);
    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $otherUser->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->postJson("/v1/tasks/{$this->task->public_id}/confidential-participants", [
            'user_id' => $this->user->public_id,
        ]);

    $response->assertForbidden();
});

it('does not add participant to non-confidential task', function () {
    $publicTask = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->initiator->id,
        'classification_level' => ClassificationLevel::Public,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$publicTask->public_id}/confidential-participants", [
            'user_id' => $this->user->public_id,
        ]);

    $response->assertStatus(422);
});
