<?php

use App\Models\User;
use App\Modules\Iam\Models\Delegation;
use App\Modules\Iam\Services\DelegationExpiryService;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Expiry Test',
        'name_ar' => 'اختبار',
        'slug' => 'exp-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->service = app(DelegationExpiryService::class);
    $this->delegator = User::factory()->create();
    $this->delegate = User::factory()->create();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('expires delegations with past ends_at', function () {
    $expired = Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDay(),
        'scope_type' => 1,
        'is_active' => true,
    ]);

    $active = Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
        'is_active' => true,
    ]);

    $count = $this->service->expire();

    expect($count)->toBe(1);
    expect($expired->fresh()->is_active)->toBeFalse();
    expect($active->fresh()->is_active)->toBeTrue();
});

it('is idempotent when running twice', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDay(),
        'scope_type' => 1,
        'is_active' => true,
    ]);

    $firstRun = $this->service->expire();
    $secondRun = $this->service->expire();

    expect($firstRun)->toBe(1);
    expect($secondRun)->toBe(0);
});

it('leaves active delegations untouched', function () {
    Delegation::create([
        'delegator_user_id' => $this->delegator->id,
        'delegate_user_id' => $this->delegate->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
        'is_active' => true,
    ]);

    $count = $this->service->expire();

    expect($count)->toBe(0);
});
