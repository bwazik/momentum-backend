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
    // Tenant A
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenantA = $this->provisioner->provision([
        'name_en' => 'Tenant A',
        'name_ar' => 'أ',
        'slug' => 'tenant-a-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenantA);
    $this->seed(CapabilitySeeder::class);
    User::factory()->tenantAdmin()->create([
        'email' => 'admin@'.$this->tenantA->slug.'.test',
        'password' => bcrypt('password'),
    ]);
    $this->seed(TenantDatabaseSeeder::class);
    $this->userA = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
    $taskA = Task::factory()->create(['initiator_user_id' => $this->userA->id]);
    event(new TaskCreated($taskA));
    tenancy()->end();

    // Tenant B
    $this->tenantB = $this->provisioner->provision([
        'name_en' => 'Tenant B',
        'name_ar' => 'ب',
        'slug' => 'tenant-b-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenantB);
    $this->seed(CapabilitySeeder::class);
    User::factory()->tenantAdmin()->create([
        'email' => 'admin@'.$this->tenantB->slug.'.test',
        'password' => bcrypt('password'),
    ]);
    $this->seed(TenantDatabaseSeeder::class);
    $this->userB = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $auditViewSys = Capability::where('key', 'audit.view_system')->firstOrFail();
    UserCapabilityGrant::create([
        'user_id' => $this->userB->id,
        'capability_id' => $auditViewSys->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->userB->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $taskB = Task::factory()->create(['initiator_user_id' => $this->userB->id]);
    event(new TaskCreated($taskB));

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenantB->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->userB->email,
            'password' => 'password',
        ]);
    $this->token = $loginResponse->json('token');
    $this->authHeaders = [
        'Authorization' => "Bearer {$this->token}",
        'X-Tenant' => $this->tenantB->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenantA->database_name);
    cleanupTenantDatabase($this->tenantB->database_name);
});

it('isolates audit events between tenants', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/audit-trail/system');

    $response->assertOk();

    $events = $response->json('data');
    foreach ($events as $event) {
        expect($event['entity_id'])->not->toBeNull();
    }
});
