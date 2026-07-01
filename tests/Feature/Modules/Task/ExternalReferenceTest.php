<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\ExternalEntityType;
use App\Modules\Task\Enums\ExternalReferenceType;
use App\Modules\Task\Events\ExternalEntityCreated;
use App\Modules\Task\Events\ExternalReferenceCreated;
use App\Modules\Task\Events\ExternalReferenceDeleted;
use App\Modules\Task\Models\ExternalEntity;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskExternalReference;
use App\Modules\Task\Models\TaskPriority;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'External Reference Test',
        'name_ar' => 'اختبار',
        'slug' => 'ext-ref-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $priority = TaskPriority::where('is_default', true)->first();

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
    $this->task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);

    $orgCapability = Capability::where('key', 'task.view.organization')->first();
    if ($orgCapability) {
        UserCapabilityGrant::create([
            'user_id' => $this->user->id,
            'capability_id' => $orgCapability->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Test grant',
        ]);
    }

    // Grant task.manage_external_entities capability
    $manageEntitiesCap = Capability::where('key', 'task.manage_external_entities')->first();
    if ($manageEntitiesCap) {
        UserCapabilityGrant::create([
            'user_id' => $this->user->id,
            'capability_id' => $manageEntitiesCap->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Test grant',
        ]);
    }

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);
    $this->token = $loginResponse->json('token');
    $this->authHeaders = [
        'Authorization' => 'Bearer '.$this->token,
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

// ─── External Entity CRUD ─────────────────────────────────────

it('lists active external entities', function () {
    ExternalEntity::factory()->count(3)->create();
    ExternalEntity::factory()->inactive()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tasks/external-entities');

    $response->assertOk()
        ->assertJsonCount(3);
});

it('creates an external entity', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks/external-entities', [
            'name_ar' => 'وزارة المالية',
            'name_en' => 'Ministry of Finance',
            'entity_type' => ExternalEntityType::GovernmentMinistry->value,
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'وزارة المالية')
        ->assertJsonPath('name_en', 'Ministry of Finance')
        ->assertJsonPath('is_active', true)
        ->assertJsonMissingPath('id');

    expect(ExternalEntity::count())->toBe(1);
});

it('creates external entity with name_en fallback to name_ar', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks/external-entities', [
            'name_ar' => 'وزارة المالية',
            'name_en' => '',
            'entity_type' => ExternalEntityType::GovernmentMinistry->value,
        ]);

    $response->assertOk()
        ->assertJsonPath('name_en', 'وزارة المالية');
});

it('rejects external entity creation without manage capability', function () {
    $userWithoutCap = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $userWithoutCap->email,
            'password' => 'password',
        ]);
    $token = $loginResponse->json('token');
    $headers = [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->postJson('/v1/tasks/external-entities', [
            'name_ar' => 'وزارة',
            'entity_type' => ExternalEntityType::GovernmentMinistry->value,
        ]);

    $response->assertForbidden();
});

it('rejects external entity missing name_ar', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks/external-entities', [
            'entity_type' => ExternalEntityType::GovernmentMinistry->value,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('name_ar');
});

it('shows an external entity', function () {
    $entity = ExternalEntity::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/external-entities/{$entity->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $entity->public_id)
        ->assertJsonPath('name_ar', $entity->name_ar);
});

it('updates an external entity', function () {
    $entity = ExternalEntity::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/tasks/external-entities/{$entity->public_id}", [
            'name_ar' => 'محدث',
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'محدث');
});

it('deactivates and reactivates an external entity', function () {
    $entity = ExternalEntity::factory()->create(['is_active' => true]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/external-entities/{$entity->public_id}/deactivate");

    $response->assertOk()
        ->assertJsonPath('is_active', false);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/external-entities/{$entity->public_id}/reactivate");

    $response->assertOk()
        ->assertJsonPath('is_active', true);
});

// ─── Task External Reference CRUD ─────────────────────────────

it('creates a task external reference', function () {
    $entity = ExternalEntity::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/external-references", [
            'reference_type' => ExternalReferenceType::Correspondence->value,
            'reference_number' => 'وارد-2026-00412',
            'external_entity_id' => $entity->public_id,
            'notes' => 'Received via official channel',
        ]);

    $response->assertOk()
        ->assertJsonPath('reference_number', 'وارد-2026-00412')
        ->assertJsonStructure([
            'public_id',
            'reference_type',
            'reference_number',
            'external_entity' => ['public_id', 'name_ar', 'name_en', 'entity_type', 'is_active'],
            'notes',
            'created_at',
        ]);

    expect(TaskExternalReference::count())->toBe(1);
});

it('rejects creating reference with inactive entity', function () {
    $entity = ExternalEntity::factory()->inactive()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/external-references", [
            'reference_type' => ExternalReferenceType::Correspondence->value,
            'reference_number' => 'وارد-2026-00412',
            'external_entity_id' => $entity->public_id,
        ]);

    $response->assertStatus(422)
        ->assertJson(['message' => __('task.exceptions.external_entity_inactive')]);
});

it('creates reference without external entity', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/external-references", [
            'reference_type' => ExternalReferenceType::Other->value,
            'reference_number' => 'REF-001',
        ]);

    $response->assertOk()
        ->assertJsonPath('reference_number', 'REF-001')
        ->assertJsonMissingPath('external_entity');
});

it('rejects invalid reference_type', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/external-references", [
            'reference_type' => 99,
            'reference_number' => 'REF-001',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('reference_type');
});

it('lists task external references with cursor pagination', function () {
    TaskExternalReference::factory()
        ->count(5)
        ->create(['task_id' => $this->task->id]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/external-references?per_page=3");

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(3, 'data');
});

it('updates a task external reference', function () {
    $entity = ExternalEntity::factory()->create();
    $reference = TaskExternalReference::factory()->create([
        'task_id' => $this->task->id,
        'external_entity_id' => $entity->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/tasks/{$this->task->public_id}/external-references/{$reference->public_id}", [
            'reference_number' => 'UPDATED-001',
            'notes' => 'Updated notes',
        ]);

    $response->assertOk()
        ->assertJsonPath('reference_number', 'UPDATED-001')
        ->assertJsonPath('notes', 'Updated notes');
});

it('deletes a task external reference', function () {
    $reference = TaskExternalReference::factory()->create([
        'task_id' => $this->task->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/tasks/{$this->task->public_id}/external-references/{$reference->public_id}");

    $response->assertNoContent();

    expect(TaskExternalReference::count())->toBe(0);
});

// ─── Authorization ────────────────────────────────────────────

it('rejects reference list without task visibility', function () {
    $otherUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $otherUser->email,
            'password' => 'password',
        ]);
    $token = $loginResponse->json('token');
    $headers = [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$this->task->public_id}/external-references");

    $response->assertForbidden();
});

it('rejects reference creation by non-initiator without task.manage', function () {
    $otherUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $otherUser->email,
            'password' => 'password',
        ]);
    $token = $loginResponse->json('token');
    $headers = [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->postJson("/v1/tasks/{$this->task->public_id}/external-references", [
            'reference_type' => ExternalReferenceType::Other->value,
            'reference_number' => 'REF-001',
        ]);

    $response->assertForbidden();
});

// ─── Search Integration ───────────────────────────────────────

it('searches tasks by exact external reference number', function () {
    $entity = ExternalEntity::factory()->create();
    TaskExternalReference::factory()->create([
        'task_id' => $this->task->id,
        'reference_number' => 'وارد-2026-00412',
        'external_entity_id' => $entity->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/search/tasks?external_reference=وارد-2026-00412');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(1, 'data');

    $taskData = $response->json('data')[0];
    expect($taskData)->toHaveKey('external_references');
    expect($taskData['external_references'][0]['reference_number'])->toBe('وارد-2026-00412');
    expect($taskData['external_references'][0]['external_entity'])->not->toBeNull();
});

it('returns empty results for non-matching external reference', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/search/tasks?external_reference=NONEXISTENT');

    $response->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('has_more', false);
});

// ─── Confidential Task ────────────────────────────────────────

it('confidential task references are not visible without access', function () {
    $confTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => TaskPriority::where('is_default', true)->first()->id,
        'classification_level' => ClassificationLevel::Confidential,
    ]);

    $otherUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $otherUser->email,
            'password' => 'password',
        ]);
    $token = $loginResponse->json('token');
    $headers = [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$confTask->public_id}/external-references");

    $response->assertForbidden();
});

// ─── Audit Events ──────────────────────────────────────────────

it('records audit event on external entity creation', function () {
    $entity = ExternalEntity::factory()->create();

    event(new ExternalEntityCreated($entity, $this->user));

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'external_entity.created',
    ]);
});

it('records audit event on reference creation and deletion', function () {
    $entity = ExternalEntity::factory()->create();
    $reference = TaskExternalReference::factory()->create([
        'task_id' => $this->task->id,
        'external_entity_id' => $entity->id,
    ]);

    event(new ExternalReferenceCreated($reference, $this->user));

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'external_reference.created',
    ]);

    event(new ExternalReferenceDeleted($reference, $this->user));

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'external_reference.deleted',
    ]);
});

// ─── Cache Invalidation ───────────────────────────────────────

it('invalidates entity cache on creation', function () {
    $this->withHeaders($this->authHeaders)
        ->postJson('/v1/tasks/external-entities', [
            'name_ar' => 'وزارة المالية',
            'entity_type' => ExternalEntityType::GovernmentMinistry->value,
        ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tasks/external-entities');

    $response->assertOk()->assertJsonCount(1);
});

// ─── Name EN Preservation ─────────────────────────────────────

it('preserves name_en when updating only name_ar', function () {
    $entity = ExternalEntity::factory()->create([
        'name_ar' => 'وزارة المالية',
        'name_en' => 'Ministry of Finance',
    ]);

    $this->withHeaders($this->authHeaders)
        ->putJson("/v1/tasks/external-entities/{$entity->public_id}", [
            'name_ar' => 'وزارة جديدة',
        ]);

    expect($entity->fresh()->name_en)->toBe('Ministry of Finance');
});
