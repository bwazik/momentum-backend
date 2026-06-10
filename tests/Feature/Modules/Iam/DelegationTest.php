<?php

use App\Models\User;
use App\Modules\Iam\Exceptions\CannotDelegateToSelfException;
use App\Modules\Iam\Models\Delegation;
use App\Modules\Iam\Services\DelegationService;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM Delegation Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-del-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->service = app(DelegationService::class);
    $this->delegator = User::factory()->create();
    $this->delegate = User::factory()->create();
});

afterEach(function () {
    Delegation::whereNotNull('id')->delete();
    User::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('creates a delegation', function () {
    $delegation = $this->service->create([
        'delegate_user_id' => $this->delegate->public_id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
    ], $this->delegator);

    expect($delegation)
        ->delegator_user_id->toBe($this->delegator->id)
        ->delegate_user_id->toBe($this->delegate->id)
        ->is_active->toBeTrue()
        ->public_id->not->toBeEmpty();
});

it('prevents self-delegation', function () {
    $this->expectException(CannotDelegateToSelfException::class);

    $this->service->create([
        'delegate_user_id' => $this->delegator->public_id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
    ], $this->delegator);
});

it('revokes a delegation', function () {
    $delegation = $this->service->create([
        'delegate_user_id' => $this->delegate->public_id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(7),
        'scope_type' => 1,
    ], $this->delegator);

    $revoked = $this->service->revoke($delegation);
    expect($revoked->is_active)->toBeFalse();
});
