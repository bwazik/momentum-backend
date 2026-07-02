<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\ConfidentialAccessEvent;
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
        'name_en' => 'Conf Access Test',
        'name_ar' => 'اختبار',
        'slug' => 'conf-access-'.uniqid(),
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

    // User with view_metadata capability (granted via position)
    $this->metadataUser = User::factory()->create(['password' => bcrypt('password')]);

    // User with view_override capability
    $this->overrideUser = User::factory()->create(['password' => bcrypt('password')]);

    // Initiate login as metadata user
    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->metadataUser->email,
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
        'initiator_user_id' => User::factory()->create()->id,
        'classification_level' => ClassificationLevel::Confidential,
        'status' => TaskStatus::Active,
        'launched_at' => now(),
    ]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('denies metadata view without capability', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/metadata");

    $response->assertForbidden();
});

it('denies override access without capability', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/access-override", [
            'reason' => 'Investigation review mandate requires access to this task for compliance purposes.',
        ]);

    $response->assertForbidden();
});

it('returns 422 for override on non-confidential task', function () {
    $publicTask = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => User::factory()->create()->id,
        'classification_level' => ClassificationLevel::Public,
        'status' => TaskStatus::Draft,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$publicTask->public_id}/access-override", [
            'reason' => 'Investigation review mandate requires access to this task for compliance purposes.',
        ]);

    $response->assertStatus(422);
});

it('returns 404 for metadata when caller has full visibility', function () {
    // Login as the task initiator (who has full visibility)
    $initiator = User::find($this->task->initiator_user_id);
    $login = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $initiator->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$login->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$this->task->public_id}/metadata");

    $response->assertStatus(404);
});

it('returns redacted metadata for user with view_metadata capability', function () {
    $viewer = User::factory()->create(['password' => bcrypt('password')]);
    $metaCap = Capability::where('key', 'task.confidential.view_metadata')->first();
    UserCapabilityGrant::create([
        'user_id' => $viewer->id,
        'capability_id' => $metaCap->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => User::factory()->create()->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $login = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $viewer->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$login->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$this->task->public_id}/metadata");

    $response->assertOk()
        ->assertJsonPath('public_id', $this->task->public_id)
        ->assertJsonPath('metadata_only', true)
        ->assertJsonStructure(['title', 'status', 'owning_department', 'due_date']);
});

it('returns full task for user with override capability', function () {
    $overrider = User::factory()->create(['password' => bcrypt('password')]);
    $overrideCap = Capability::where('key', 'task.confidential.view_override')->first();
    UserCapabilityGrant::create([
        'user_id' => $overrider->id,
        'capability_id' => $overrideCap->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => User::factory()->create()->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $login = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $overrider->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$login->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->postJson("/v1/tasks/{$this->task->public_id}/access-override", [
            'reason' => 'Investigation review mandate requires access to this task for compliance purposes.',
        ]);

    $response->assertOk()
        ->assertJsonPath('public_id', $this->task->public_id)
        ->assertJsonStructure(['title_ar', 'status', 'stages']);
});

it('lists confidential access events with cursor pagination', function () {
    TaskConfidentialParticipant::create([
        'task_id' => $this->task->id,
        'user_id' => $this->metadataUser->id,
        'added_by_user_id' => $this->task->initiator_user_id,
        'added_at' => now(),
    ]);

    ConfidentialAccessEvent::create([
        'task_id' => $this->task->id,
        'user_id' => $this->metadataUser->id,
        'access_type' => 2,
        'reason' => 'Test override',
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/confidential-access-events");

    $response->assertOk()
        ->assertJsonStructure(['data' => [['user', 'access_type', 'reason', 'created_at']], 'next_cursor', 'has_more']);
});
