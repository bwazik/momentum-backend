<?php

use App\Models\User;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Models\Task;
use App\Modules\Tracking\Models\Escalation;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Escalation API Test',
        'name_ar' => 'اختبار',
        'slug' => 'eapi-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->calendar = WorkingCalendar::factory()->create(['is_default' => true]);

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->task = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('lists escalations with cursor pagination', function () {
    Escalation::factory()->count(5)->create([
        'task_id' => $this->task->id,
        'escalated_to_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tracking/escalations?per_page=3');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'status', 'escalation_type', 'reason']], 'next_cursor', 'has_more']);
});

it('filters escalations by status', function () {
    Escalation::factory()->open()->create([
        'task_id' => $this->task->id,
        'escalated_to_user_id' => $this->user->id,
    ]);
    Escalation::factory()->resolved()->create([
        'task_id' => $this->task->id,
        'escalated_to_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/tracking/escalations?status=1');

    $response->assertOk();
    expect(count($response->json('data')))->toBe(1);
    expect($response->json('data.0.status'))->toBe(1);
});

it('shows escalation detail', function () {
    $escalation = Escalation::factory()->create([
        'task_id' => $this->task->id,
        'escalated_to_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tracking/escalations/{$escalation->public_id}");

    $response->assertOk()
        ->assertJsonStructure([
            'public_id', 'task_id', 'reason', 'status', 'escalation_type',
            'escalated_to_user' => ['public_id', 'name_ar', 'name_en'],
        ]);
});
