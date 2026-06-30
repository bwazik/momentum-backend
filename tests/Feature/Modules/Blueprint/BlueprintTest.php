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
        'name_en' => 'Blueprint Test',
        'name_ar' => 'اختبار',
        'slug' => 'bp-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->category = BlueprintCategory::factory()->create();
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

it('creates a blueprint with organization scope', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/blueprints', [
            'name_ar' => 'قالب تجريبي',
            'name_en' => 'Test Blueprint',
            'category_id' => $this->category->public_id,
            'scope' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('name_ar', 'قالب تجريبي')
        ->assertJsonPath('scope', 'organization')
        ->assertJsonPath('is_locked', false)
        ->assertJsonPath('is_active', true);

    $this->assertDatabaseHas('blueprints', [
        'name_ar' => 'قالب تجريبي',
        'scope' => 1,
        'is_active' => true,
        'is_locked' => false,
    ]);
});

it('fails to create blueprint with department scope without department_id', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/blueprints', [
            'name_ar' => 'قالب قسم',
            'category_id' => $this->category->public_id,
            'scope' => 2,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('department_id');
});

it('shows a blueprint with full structure', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/blueprints/{$blueprint->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $blueprint->public_id);
});

it('lists blueprints with cursor pagination', function () {
    Blueprint::factory()->count(3)->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/blueprints');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'name_ar', 'scope']]]);
});

it('activates a blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => false,
    ]);
    $stageType = StageType::first();
    BlueprintStage::factory()->create([
        'blueprint_id' => $blueprint->id,
        'stage_type_id' => $stageType->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$blueprint->public_id}/activate");

    $response->assertOk()
        ->assertJsonPath('is_active', true);

    expect($blueprint->fresh()->is_active)->toBeTrue();
});

it('rejects activating a blueprint with no stages', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_active' => false,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$blueprint->public_id}/activate");

    $response->assertStatus(500);
});

it('deactivates a blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$blueprint->public_id}/deactivate");

    $response->assertOk()
        ->assertJsonPath('is_active', false);

    expect($blueprint->fresh()->is_active)->toBeFalse();
});

it('duplicates a blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$blueprint->public_id}/duplicate");

    $response->assertCreated()
        ->assertJsonPath('is_locked', false)
        ->assertJsonPath('is_active', false);

    $this->assertDatabaseHas('blueprints', [
        'is_locked' => false,
        'is_active' => false,
    ]);
});

it('rejects update on locked blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_locked' => true,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/blueprints/{$blueprint->public_id}", [
            'name_ar' => 'جديد',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Blueprint is locked and cannot be modified.');
});

it('rejects activating a locked blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_locked' => true,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$blueprint->public_id}/activate");

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Blueprint is locked and cannot be modified.');
});

it('deactivates a locked blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
        'is_locked' => true,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/{$blueprint->public_id}/deactivate");

    $response->assertOk();

    expect($blueprint->fresh()->is_active)->toBeFalse();
});

it('soft-deletes a blueprint', function () {
    $blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/{$blueprint->public_id}");

    $response->assertStatus(204);
    expect(Blueprint::where('public_id', $blueprint->public_id)->exists())->toBeFalse();
    expect(Blueprint::withTrashed()->where('public_id', $blueprint->public_id)->exists())->toBeTrue();
});
