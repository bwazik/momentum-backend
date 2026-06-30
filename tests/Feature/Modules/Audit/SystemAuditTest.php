<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
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
        'name_en' => 'System Audit Test',
        'name_ar' => 'اختبار سجل النظام',
        'slug' => 'sys-audit-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);

    User::factory()->tenantAdmin()->create([
        'email' => 'admin@'.$this->tenant->slug.'.test',
        'password' => bcrypt('password'),
    ]);

    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $auditViewSys = Capability::where('key', 'audit.view_system')->firstOrFail();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $auditViewSys->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('returns system audit log for users with audit.view_system', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/system');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'event_type', 'entity_type', 'created_at']], 'next_cursor', 'has_more']);
});

it('requires audit.view_system capability', function () {
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
        ->getJson('/v1/audit-trail/system');

    $response->assertForbidden();
});

it('filters system audit log by event_type', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/system?event_type=task.created');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters system audit log by date range', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/system?date_from='.now()->subDay()->format('Y-m-d\TH:i:s').'&date_to='.now()->addDay()->format('Y-m-d\TH:i:s'));

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters system audit log by user_id', function () {
    $otherUser = User::factory()->create(['password' => bcrypt('password')]);
    $task1 = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    $task2 = Task::factory()->create(['initiator_user_id' => $otherUser->id]);
    event(new TaskCreated($task1));
    event(new TaskCreated($task2));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/system?user_id='.$this->user->public_id);

    $response->assertOk();
    $events = $response->json('data');
    foreach ($events as $event) {
        expect($event['performed_by']['public_id'])->toBe($this->user->public_id);
    }
});

it('returns 403 for user without audit.view_system', function () {
    $internalUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $internalUser->email,
            'password' => 'password',
        ]);
    $headers = [
        'Authorization' => "Bearer {$loginResponse->json('token')}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson('/v1/audit-trail/system');

    $response->assertForbidden();
});
