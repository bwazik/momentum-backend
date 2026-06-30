<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintTransition;
use App\Modules\Blueprint\Models\StageType;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Transition Test',
        'name_ar' => 'اختبار',
        'slug' => 'trans-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $category = BlueprintCategory::factory()->create();
    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
    $this->blueprint = Blueprint::factory()->create([
        'category_id' => $category->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $stageType = StageType::first();
    $this->fromStage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $stageType->id,
        'sequence_order' => 1,
    ]);
    $this->toStage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $stageType->id,
        'sequence_order' => 2,
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

it('creates an advance transition', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson(
            "/v1/blueprints/{$this->blueprint->public_id}/transitions",
            [
                'from_stage_id' => $this->fromStage->public_id,
                'to_stage_id' => $this->toStage->public_id,
                'transition_type' => 1,
            ]
        );

    $response->assertCreated();

    $this->assertDatabaseHas('blueprint_transitions', [
        'blueprint_id' => $this->blueprint->id,
        'transition_type' => 1,
    ]);
});

it('creates a return transition', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson(
            "/v1/blueprints/{$this->blueprint->public_id}/transitions",
            [
                'from_stage_id' => $this->toStage->public_id,
                'to_stage_id' => $this->fromStage->public_id,
                'transition_type' => 2,
                'return_reason_required' => true,
            ]
        );

    $response->assertCreated();

    expect(BlueprintTransition::where('transition_type', 2)->exists())->toBeTrue();
});

it('rejects self-loop transition', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson(
            "/v1/blueprints/{$this->blueprint->public_id}/transitions",
            [
                'from_stage_id' => $this->fromStage->public_id,
                'to_stage_id' => $this->fromStage->public_id,
                'transition_type' => 1,
            ]
        );

    $response->assertStatus(422);
});

it('rejects advance transition to lower sequence', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson(
            "/v1/blueprints/{$this->blueprint->public_id}/transitions",
            [
                'from_stage_id' => $this->toStage->public_id,
                'to_stage_id' => $this->fromStage->public_id,
                'transition_type' => 1,
            ]
        );

    $response->assertStatus(422);
});

it('rejects return transition to higher sequence', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson(
            "/v1/blueprints/{$this->blueprint->public_id}/transitions",
            [
                'from_stage_id' => $this->fromStage->public_id,
                'to_stage_id' => $this->toStage->public_id,
                'transition_type' => 2,
            ]
        );

    $response->assertStatus(422);
});

it('lists transitions', function () {
    BlueprintTransition::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'from_stage_id' => $this->fromStage->id,
        'to_stage_id' => $this->toStage->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/blueprints/{$this->blueprint->public_id}/transitions");

    $response->assertOk()
        ->assertJsonStructure(['*' => ['public_id', 'transition_type']]);
});

it('rejects transition on locked blueprint', function () {
    $this->blueprint->update(['is_locked' => true]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson(
            "/v1/blueprints/{$this->blueprint->public_id}/transitions",
            [
                'from_stage_id' => $this->fromStage->public_id,
                'to_stage_id' => $this->toStage->public_id,
                'transition_type' => 1,
            ]
        );

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Blueprint is locked and cannot be modified.');
});
