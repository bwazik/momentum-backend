<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Services\GrantService;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM UserGrant Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-usergrant-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);

    $this->service = app(GrantService::class);
    $this->adminUser = User::factory()->tenantAdmin()->create();
    $this->user = User::factory()->create();
    $this->capability = Capability::where('key', 'task.view.organization')->first();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('grants a capability directly to a user', function () {
    $grant = $this->service->grantToUser(
        $this->user,
        [
            'capability_id' => $this->capability->public_id,
            'scope_type' => ScopeType::TENANT->value,
            'reason' => 'Override needed for audit access',
        ],
        $this->adminUser
    );

    expect($grant)
        ->user_id->toBe($this->user->id)
        ->capability_id->toBe($this->capability->id)
        ->reason->toBe('Override needed for audit access')
        ->revoked_at->toBeNull();
});

it('revokes a user capability grant', function () {
    $grant = $this->service->grantToUser(
        $this->user,
        [
            'capability_id' => $this->capability->public_id,
            'scope_type' => ScopeType::TENANT->value,
            'reason' => 'Temporary access',
        ],
        $this->adminUser
    );

    $revoked = $this->service->revokeUserGrant($grant);
    expect($revoked->revoked_at)->not->toBeNull();
});
