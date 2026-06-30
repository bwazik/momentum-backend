<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\AuditGrant;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Events\TaskCreated;
use App\Modules\Task\Models\Task;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Audit Trail Test',
        'name_ar' => 'اختبار سجل التدقيق',
        'slug' => 'audit-trail-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);

    User::factory()->tenantAdmin()->create([
        'email' => 'admin@'.$this->tenant->slug.'.test',
        'password' => bcrypt('password'),
    ]);

    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $capabilities = Capability::whereIn('key', ['task.view.organization', 'audit.view_task'])->get();
    foreach ($capabilities as $cap) {
        UserCapabilityGrant::create([
            'user_id' => $this->user->id,
            'capability_id' => $cap->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Test grant',
        ]);
    }

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->task = Task::factory()->create([
        'initiator_user_id' => $this->user->id,
    ]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('returns task audit trail via API', function () {
    event(new TaskCreated($this->task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/audit-trail");

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'event_type', 'entity_type', 'entity_id', 'created_at']], 'next_cursor', 'has_more'])
        ->assertJsonPath('data.0.event_type', 'task.created');
});

it('requires audit.view_task capability', function () {
    $noCapUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $noCapUser->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$this->task->public_id}/audit-trail");

    $response->assertForbidden();
});

it('returns 403 when user cannot see the task', function () {
    $restrictedUser = User::factory()->create(['password' => bcrypt('password')]);

    $auditViewTask = Capability::where('key', 'audit.view_task')->first();
    if ($auditViewTask) {
        UserCapabilityGrant::create([
            'user_id' => $restrictedUser->id,
            'capability_id' => $auditViewTask->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Test grant',
        ]);
    }

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $restrictedUser->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$this->task->public_id}/audit-trail");

    $response->assertForbidden();
});

it('allows external auditor with valid grant to view completed task trail', function () {
    $auditor = User::factory()->externalAuditor()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $auditor->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->task->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);

    AuditGrant::create([
        'external_auditor_user_id' => $auditor->id,
        'granted_by_user_id' => $this->user->id,
        'date_range_start' => now()->subDays(1),
        'date_range_end' => now()->addDays(30),
        'granted_at' => now(),
    ]);

    event(new TaskCreated($this->task));

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$this->task->public_id}/audit-trail");

    $response->assertOk()
        ->assertJsonPath('data.0.event_type', 'task.created');
});

it('blocks external auditor on active tasks', function () {
    $auditor = User::factory()->externalAuditor()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $auditor->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    AuditGrant::create([
        'external_auditor_user_id' => $auditor->id,
        'granted_by_user_id' => $this->user->id,
        'date_range_start' => now()->subDays(1),
        'date_range_end' => now()->addDays(30),
        'granted_at' => now(),
    ]);

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$this->task->public_id}/audit-trail");

    $response->assertForbidden();
});

it('blocks external auditor without valid grant', function () {
    $auditor = User::factory()->externalAuditor()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $auditor->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->task->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);

    $response = $this->withHeaders($headers)
        ->getJson("/v1/tasks/{$this->task->public_id}/audit-trail");

    $response->assertForbidden();
});

it('includes ip_address and user_agent in task trail response', function () {
    event(new TaskCreated($this->task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/audit-trail");

    $response->assertOk();
    expect($response->json('data.0.ip_address'))->not->toBeNull();
});

it('returns cursor pagination shape', function () {
    event(new TaskCreated($this->task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/audit-trail?per_page=1");

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more']);
});
