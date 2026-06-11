<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Blueprint\Models\StageType;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'SLA Policy Test',
        'name_ar' => 'اختبار',
        'slug' => 'sla-pol-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

    $this->token = $loginResponse->json('token');
    $this->authHeaders = [
        'Authorization' => "Bearer {$this->token}",
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    SlaPolicy::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('lists SLA policies', function () {
    SlaPolicy::factory()->count(2)->create();

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/blueprints/sla-policies');

    $response->assertOk()
        ->assertJsonStructure(['*' => ['public_id', 'name_ar', 'sla_value']]);
});

it('creates an SLA policy', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/blueprints/sla-policies', [
            'name_ar' => 'سريع',
            'name_en' => 'Fast',
            'sla_value' => 24,
            'sla_unit' => 1,
            'warning_threshold_percentage' => 80,
        ]);

    $response->assertCreated()
        ->assertJsonPath('name_ar', 'سريع')
        ->assertJsonPath('sla_value', 24);

    $this->assertDatabaseHas('sla_policies', [
        'name_ar' => 'سريع',
        'sla_value' => 24,
        'sla_unit' => 1,
        'warning_threshold_percentage' => 80,
    ]);
});

it('defaults warning_threshold_percentage to 75', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/blueprints/sla-policies', [
            'name_ar' => 'افتراضي',
            'sla_value' => 8,
            'sla_unit' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('warning_threshold_percentage', 75);
});

it('updates an SLA policy', function () {
    $policy = SlaPolicy::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/blueprints/sla-policies/{$policy->public_id}", [
            'name_ar' => 'جديد',
            'sla_value' => 48,
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'جديد')
        ->assertJsonPath('sla_value', 48);

    expect($policy->fresh()->sla_value)->toBe(48);
});

it('deletes an unused SLA policy', function () {
    $policy = SlaPolicy::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/sla-policies/{$policy->public_id}");

    $response->assertStatus(204);
    expect(SlaPolicy::where('id', $policy->id)->exists())->toBeFalse();
});

it('rejects deleting an SLA policy in use by a stage', function () {
    $policy = SlaPolicy::factory()->create();
    $category = BlueprintCategory::factory()->create();
    $blueprint = Blueprint::factory()->create(['category_id' => $category->id]);
    $stageType = StageType::first();
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
        'sla_policy_id' => $policy->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/sla-policies/{$policy->public_id}");

    $response->assertStatus(422);
});
