<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\StageType;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Stage Type Test',
        'name_ar' => 'اختبار',
        'slug' => 'st-type-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
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
    $this->tenant->delete();
});

it('lists active stage types', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/blueprints/stage-types');

    $response->assertOk()
        ->assertJsonStructure(['*' => ['public_id', 'name_ar', 'is_system_default']]);
});

it('creates a custom stage type', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/blueprints/stage-types', [
            'name_ar' => 'تقييم',
            'name_en' => 'Evaluation',
            'display_order' => 6,
        ]);

    $response->assertCreated()
        ->assertJsonPath('name_ar', 'تقييم');

    $this->assertDatabaseHas('stage_types', [
        'name_ar' => 'تقييم',
        'is_system_default' => false,
    ]);
});

it('defaults name_en to name_ar for custom stage type', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/blueprints/stage-types', [
            'name_ar' => 'فحص',
        ]);

    $response->assertCreated()
        ->assertJsonPath('name_en', 'فحص');
});

it('updates a custom stage type', function () {
    $stageType = StageType::where('is_system_default', false)->first();
    if (! $stageType) {
        $stageType = StageType::factory()->create();
    }

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/blueprints/stage-types/{$stageType->public_id}", [
            'name_ar' => 'محدث',
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'محدث');

    expect($stageType->fresh()->name_ar)->toBe('محدث');
});

it('rejects deleting a system default stage type', function () {
    $systemDefault = StageType::where('is_system_default', true)->first();

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/stage-types/{$systemDefault->public_id}");

    $response->assertStatus(422);
});

it('rejects deleting a stage type in use by a blueprint stage', function () {
    $stageType = StageType::where('is_system_default', false)->first();
    if (! $stageType) {
        $stageType = StageType::factory()->create();
    }
    $category = BlueprintCategory::factory()->create();
    $blueprint = Blueprint::factory()->create(['category_id' => $category->id]);
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/stage-types/{$stageType->public_id}");

    $response->assertStatus(422);
});

it('deletes a custom stage type not in use', function () {
    $stageType = StageType::factory()->create(['is_system_default' => false]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/stage-types/{$stageType->public_id}");

    $response->assertStatus(204);
    expect(StageType::where('id', $stageType->id)->exists())->toBeFalse();
});
