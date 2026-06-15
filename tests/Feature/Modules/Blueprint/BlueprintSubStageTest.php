<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Blueprint\Models\StageType;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'SubStage Test',
        'name_ar' => 'اختبار',
        'slug' => 'substg-'.uniqid(),
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
    $this->stage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $stageType->id,
    ]);

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    BlueprintSubStage::whereNotNull('id')->forceDelete();
    BlueprintStage::whereNotNull('id')->forceDelete();
    Blueprint::whereNotNull('id')->forceDelete();
    BlueprintCategory::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('adds a sub-stage to a stage', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson(
            "/v1/blueprints/{$this->blueprint->public_id}/stages/{$this->stage->public_id}/sub-stages",
            [
                'name_ar' => 'إدخال بيانات',
                'name_en' => 'Data Entry',
                'assignment_type' => 3,
                'sequence_order' => 1,
            ]
        );

    $response->assertCreated()
        ->assertJsonPath('name_ar', 'إدخال بيانات');

    $this->assertDatabaseHas('blueprint_sub_stages', [
        'name_ar' => 'إدخال بيانات',
        'is_required' => true,
    ]);
});

it('lists sub-stages in order', function () {
    BlueprintSubStage::factory()->count(2)->create([
        'blueprint_stage_id' => $this->stage->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson(
            "/v1/blueprints/{$this->blueprint->public_id}/stages/{$this->stage->public_id}/sub-stages"
        );

    $response->assertOk()
        ->assertJsonStructure(['*' => ['public_id', 'name_ar', 'sequence_order']]);
});

it('updates a sub-stage', function () {
    $subStage = BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $this->stage->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->putJson(
            "/v1/blueprints/{$this->blueprint->public_id}/stages/{$this->stage->public_id}/sub-stages/{$subStage->public_id}",
            ['name_ar' => 'جديد']
        );

    $response->assertOk()
        ->assertJsonPath('name_ar', 'جديد');

    expect($subStage->fresh()->name_ar)->toBe('جديد');
});

it('deletes a sub-stage', function () {
    $subStage = BlueprintSubStage::factory()->create([
        'blueprint_stage_id' => $this->stage->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson(
            "/v1/blueprints/{$this->blueprint->public_id}/stages/{$this->stage->public_id}/sub-stages/{$subStage->public_id}"
        );

    $response->assertStatus(204);
    expect(BlueprintSubStage::where('id', $subStage->id)->exists())->toBeFalse();
});

it('rejects sub-stage on locked blueprint', function () {
    $this->blueprint->update(['is_locked' => true]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson(
            "/v1/blueprints/{$this->blueprint->public_id}/stages/{$this->stage->public_id}/sub-stages",
            ['name_ar' => 'جديد', 'assignment_type' => 3]
        );

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Blueprint is locked and cannot be modified.');
});
