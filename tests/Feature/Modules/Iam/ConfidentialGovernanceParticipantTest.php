<?php

use App\Models\User;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Iam\Models\ConfidentialGovernanceParticipant;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\StageInstanceStatus;
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
        'name_en' => 'Conf Gov Part Test',
        'name_ar' => 'اختبار',
        'slug' => 'conf-gov-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->department = Department::factory()->create();
    $this->position = Position::factory()->create(['department_id' => $this->department->id]);
    $this->category = BlueprintCategory::factory()->create();
    $this->blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => User::factory()->create()->id,
    ]);
    $this->priority = TaskPriority::where('is_default', true)->first();

    $this->admin = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->admin->email,
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
});

it('creates governance config with tenant scope', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/confidential-governance-participants', [
            'position_id' => $this->position->public_id,
            'scope_type' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('scope_type', 1);

    $this->assertDatabaseHas('confidential_governance_participants', [
        'position_id' => $this->position->id,
        'scope_type' => 1,
        'revoked_at' => null,
    ]);
});

it('creates governance config with department scope', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/confidential-governance-participants', [
            'position_id' => $this->position->public_id,
            'scope_type' => 3,
            'scope_department_id' => $this->department->public_id,
        ]);

    $response->assertCreated();
});

it('rejects invalid scope combinations', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/confidential-governance-participants', [
            'position_id' => $this->position->public_id,
            'scope_type' => 2,
        ]);

    $response->assertStatus(422);
});

it('rejects tenant scope with department', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/confidential-governance-participants', [
            'position_id' => $this->position->public_id,
            'scope_type' => 1,
            'scope_department_id' => $this->department->public_id,
        ]);

    $response->assertStatus(422);
});

it('rejects specific_department without department', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/confidential-governance-participants', [
            'position_id' => $this->position->public_id,
            'scope_type' => 3,
        ]);

    $response->assertStatus(422);
});

it('revokes governance config', function () {
    $config = ConfidentialGovernanceParticipant::create([
        'position_id' => $this->position->id,
        'scope_type' => 1,
        'created_by_user_id' => $this->admin->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/iam/confidential-governance-participants/{$config->public_id}/revoke");

    $response->assertOk();
    expect($config->fresh()->revoked_at)->not->toBeNull();
});

it('lists governance configs with cursor pagination', function () {
    ConfidentialGovernanceParticipant::create([
        'position_id' => $this->position->id,
        'scope_type' => 1,
        'created_by_user_id' => $this->admin->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/iam/confidential-governance-participants');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'position', 'scope_type']], 'next_cursor', 'has_more']);
});

it('grants task visibility to governance participant', function () {
    $confTask = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => User::factory()->create()->id,
        'classification_level' => ClassificationLevel::Confidential,
        'status' => TaskStatus::Active,
        'launched_at' => now(),
    ]);
    $confTask->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now(),
    ]);

    ConfidentialGovernanceParticipant::create([
        'position_id' => $this->position->id,
        'scope_type' => 1,
        'created_by_user_id' => $this->admin->id,
    ]);

    $govUser = User::factory()->create(['password' => bcrypt('password')]);
    UserPositionAssignment::create([
        'user_id' => $govUser->id,
        'position_id' => $this->position->id,
        'is_primary' => true,
        'started_at' => now(),
    ]);

    $login = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $govUser->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$login->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$confTask->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $confTask->public_id);
});

it('requires iam.manage_capabilities for governance endpoints', function () {
    $regularUser = User::factory()->create(['password' => bcrypt('password')]);
    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $regularUser->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->postJson('/v1/iam/confidential-governance-participants', [
            'position_id' => $this->position->public_id,
            'scope_type' => 1,
        ]);

    $response->assertForbidden();
});
