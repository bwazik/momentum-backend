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
        'name_en' => 'Stage Test',
        'name_ar' => 'اختبار',
        'slug' => 'stage-t-'.uniqid(),
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
    $this->stageType = StageType::first();

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
    BlueprintStage::whereNotNull('id')->forceDelete();
    Blueprint::whereNotNull('id')->forceDelete();
    BlueprintCategory::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('adds a stage to a blueprint', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$this->blueprint->public_id}/stages", [
            'name_ar' => 'مراجعة',
            'name_en' => 'Review',
            'stage_type_id' => $this->stageType->public_id,
            'assignment_type' => 3,
            'sequence_order' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('name_ar', 'مراجعة');

    $this->assertDatabaseHas('blueprint_stages', [
        'name_ar' => 'مراجعة',
        'sequence_order' => 1,
    ]);
});

it('auto-assigns sequence_order when not provided', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$this->blueprint->public_id}/stages", [
            'name_ar' => 'مرحلة',
            'stage_type_id' => $this->stageType->public_id,
            'assignment_type' => 3,
        ]);

    $response->assertCreated();

    $stage = BlueprintStage::where('name_ar', 'مرحلة')->first();
    expect($stage->sequence_order)->toBe(1);
});

it('lists stages in order', function () {
    BlueprintStage::factory()->count(2)->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $this->stageType->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/blueprints/{$this->blueprint->public_id}/stages");

    $response->assertOk()
        ->assertJsonStructure(['*' => ['public_id', 'name_ar', 'sequence_order']]);
});

it('updates a stage', function () {
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $this->stageType->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/blueprints/{$this->blueprint->public_id}/stages/{$stage->public_id}", [
            'name_ar' => 'جديد',
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'جديد');

    expect($stage->fresh()->name_ar)->toBe('جديد');
});

it('deletes a stage', function () {
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $this->stageType->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/{$this->blueprint->public_id}/stages/{$stage->public_id}");

    $response->assertStatus(204);
    expect(BlueprintStage::where('id', $stage->id)->exists())->toBeFalse();
});

it('rejects stage on locked blueprint', function () {
    $this->blueprint->update(['is_locked' => true]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$this->blueprint->public_id}/stages", [
            'name_ar' => 'جديد',
            'stage_type_id' => $this->stageType->public_id,
            'assignment_type' => 3,
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Blueprint is locked and cannot be modified.');
});

it('rejects update on locked blueprint stage', function () {
    $this->blueprint->update(['is_locked' => true]);
    $stage = BlueprintStage::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'stage_type_id' => $this->stageType->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/blueprints/{$this->blueprint->public_id}/stages/{$stage->public_id}", [
            'name_ar' => 'جديد',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Blueprint is locked and cannot be modified.');
});
