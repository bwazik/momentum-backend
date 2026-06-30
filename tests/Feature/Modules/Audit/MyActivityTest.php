<?php

use App\Models\User;
use App\Modules\Task\Events\TaskCreated;
use App\Modules\Task\Events\TaskUpdated;
use App\Modules\Task\Models\Task;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'My Activity Test',
        'name_ar' => 'اختبار نشاطي',
        'slug' => 'my-activity-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);

    User::factory()->tenantAdmin()->create([
        'email' => 'admin@'.$this->tenant->slug.'.test',
        'password' => bcrypt('password'),
    ]);

    $this->seed(TenantDatabaseSeeder::class);

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

it('returns my activity for authenticated user', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/me');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'event_type', 'entity_type', 'created_at']], 'next_cursor', 'has_more']);
});

it('only returns events for the current user', function () {
    $task1 = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task1));

    $otherUser = User::factory()->create(['password' => bcrypt('password')]);
    $task2 = Task::factory()->create(['initiator_user_id' => $otherUser->id]);
    event(new TaskCreated($task2));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/me');

    $response->assertOk();
    $events = $response->json('data');
    foreach ($events as $event) {
        expect($event['performed_by']['public_id'])->toBe($this->user->public_id);
    }
});

it('omits ip_address and user_agent from my activity response', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/me');

    $response->assertOk();
    foreach ($response->json('data') as $event) {
        expect($event['ip_address'])->toBeNull();
        expect($event['user_agent'])->toBeNull();
    }
});

it('filters my activity by event_type', function () {
    $task = Task::factory()->create(['initiator_user_id' => $this->user->id]);
    event(new TaskCreated($task));
    event(new TaskUpdated($task));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/me?event_type=task.created');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.event_type'))->toBe('task.created');
});

it('is available to any internal user', function () {
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
        ->getJson('/v1/audit-trail/me');

    $response->assertOk();
});
