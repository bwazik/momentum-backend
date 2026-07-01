<?php

use App\Enums\DelegationScopeType;
use App\Models\User;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Models\Delegation;
use App\Modules\Iam\Services\IamPolicy;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Routing Test',
        'name_ar' => 'اختبار',
        'slug' => 'route-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->policy = app(IamPolicy::class);
    $this->delegator = User::factory()->create();
    $this->delegate = User::factory()->create();

    $this->category = BlueprintCategory::factory()->create();
    $this->stageType = StageType::factory()->create();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('returns delegate for ALL scope', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => DelegationScopeType::ALL->value,
        'is_active' => true,
    ]);

    $result = $this->policy->resolveDelegateForAssignment($this->delegator, null, null);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($this->delegate->id);
});

it('returns delegate for BLUEPRINT_CATEGORY scope when category matches', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => DelegationScopeType::BLUEPRINT_CATEGORY->value,
        'blueprint_category_id' => $this->category->id,
        'is_active' => true,
    ]);

    $result = $this->policy->resolveDelegateForAssignment(
        $this->delegator, $this->category->id, null
    );

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($this->delegate->id);
});

it('returns null for BLUEPRINT_CATEGORY scope when category does not match', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => DelegationScopeType::BLUEPRINT_CATEGORY->value,
        'blueprint_category_id' => $this->category->id,
        'is_active' => true,
    ]);

    $otherCategory = BlueprintCategory::factory()->create();

    $result = $this->policy->resolveDelegateForAssignment(
        $this->delegator, $otherCategory->id, null
    );

    expect($result)->toBeNull();
});

it('returns delegate for STAGE_TYPE scope when stage type matches', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => DelegationScopeType::STAGE_TYPE->value,
        'stage_type_id' => $this->stageType->id,
        'is_active' => true,
    ]);

    $result = $this->policy->resolveDelegateForAssignment(
        $this->delegator, null, $this->stageType->id
    );

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($this->delegate->id);
});

it('returns delegate for BLUEPRINT_CATEGORY_AND_STAGE_TYPE when both match', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE->value,
        'blueprint_category_id' => $this->category->id,
        'stage_type_id' => $this->stageType->id,
        'is_active' => true,
    ]);

    $result = $this->policy->resolveDelegateForAssignment(
        $this->delegator, $this->category->id, $this->stageType->id
    );

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($this->delegate->id);
});

it('returns null when only one of BLUEPRINT_CATEGORY_AND_STAGE_TYPE matches', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE->value,
        'blueprint_category_id' => $this->category->id,
        'stage_type_id' => $this->stageType->id,
        'is_active' => true,
    ]);

    $otherCategory = BlueprintCategory::factory()->create();

    $result = $this->policy->resolveDelegateForAssignment(
        $this->delegator, $otherCategory->id, $this->stageType->id
    );

    expect($result)->toBeNull();
});

it('falls back to simple OOF delegate when no scoped delegation matches', function () {
    $this->delegator->update([
        'is_out_of_office' => true,
        'out_of_office_delegate_user_id' => $this->delegate->id,
    ]);

    $result = $this->policy->resolveDelegateForAssignment($this->delegator, null, null);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($this->delegate->id);
});

it('returns null when no delegation or OOF applies', function () {
    $result = $this->policy->resolveDelegateForAssignment($this->delegator, null, null);

    expect($result)->toBeNull();
});

it('most recently created delegation wins', function () {
    $otherDelegate = User::factory()->create();

    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $otherDelegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => DelegationScopeType::ALL->value,
        'is_active' => true,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => DelegationScopeType::ALL->value,
        'is_active' => true,
    ]);

    $result = $this->policy->resolveDelegateForAssignment($this->delegator, null, null);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($this->delegate->id);
});
