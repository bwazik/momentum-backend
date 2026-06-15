<?php

use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'BP Category Test',
        'name_ar' => 'اختبار',
        'slug' => 'bp-cat-'.uniqid(),
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
    Blueprint::whereNotNull('id')->forceDelete();
    BlueprintCategory::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('lists active blueprint categories', function () {
    BlueprintCategory::factory()->count(3)->create(['is_active' => true]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/blueprints/categories');

    $response->assertOk()
        ->assertJsonStructure(['*' => ['public_id', 'name_ar', 'name_en', 'is_active']]);
});

it('creates a blueprint category', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/blueprints/categories', [
            'name_ar' => 'مالية',
            'name_en' => 'Financial',
            'display_order' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('name_ar', 'مالية')
        ->assertJsonPath('name_en', 'Financial');

    $this->assertDatabaseHas('blueprint_categories', [
        'name_ar' => 'مالية',
        'name_en' => 'Financial',
        'is_active' => true,
    ]);
});

it('defaults name_en to name_ar when not provided for category', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/blueprints/categories', [
            'name_ar' => 'مالية عامة',
        ]);

    $response->assertCreated()
        ->assertJsonPath('name_en', 'مالية عامة');

    $this->assertDatabaseHas('blueprint_categories', [
        'name_ar' => 'مالية عامة',
        'name_en' => 'مالية عامة',
    ]);
});

it('updates a blueprint category', function () {
    $category = BlueprintCategory::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->putJson("/v1/blueprints/categories/{$category->public_id}", [
            'name_ar' => 'جديد',
        ]);

    $response->assertOk()
        ->assertJsonPath('name_ar', 'جديد');

    expect($category->fresh()->name_ar)->toBe('جديد');
});

it('deactivates a blueprint category', function () {
    $category = BlueprintCategory::factory()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/categories/{$category->public_id}/deactivate");

    $response->assertOk()
        ->assertJsonPath('is_active', false);

    expect($category->fresh()->is_active)->toBeFalse();
});

it('reactivates a blueprint category', function () {
    $category = BlueprintCategory::factory()->create(['is_active' => false]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/blueprints/categories/{$category->public_id}/reactivate");

    $response->assertOk()
        ->assertJsonPath('is_active', true);

    expect($category->fresh()->is_active)->toBeTrue();
});

it('rejects deleting a category referenced by active blueprints', function () {
    $category = BlueprintCategory::factory()->create();
    Blueprint::factory()->create([
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/categories/{$category->public_id}");

    $response->assertStatus(422);
});

it('allows deleting a category with only soft-deleted blueprints', function () {
    $category = BlueprintCategory::factory()->create();
    $blueprint = Blueprint::factory()->create([
        'category_id' => $category->id,
    ]);
    $blueprint->delete();

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/blueprints/categories/{$category->public_id}");

    $response->assertStatus(204);
});
