<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Services\MonitoringScopeService;
use App\Modules\Organization\Models\Department;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM Monitor Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-mon-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->service = app(MonitoringScopeService::class);
    $this->adminUser = User::factory()->tenantAdmin()->create();
    $this->user = User::factory()->create();
    $this->dept = Department::factory()->create();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('grants a monitoring scope to a user', function () {
    $grant = $this->service->grant(
        $this->user,
        ['scope_type' => ScopeType::TENANT->value],
        $this->adminUser
    );

    expect($grant)
        ->user_id->toBe($this->user->id)
        ->revoked_at->toBeNull();
});

it('grants a monitoring scope with department', function () {
    $grant = $this->service->grant(
        $this->user,
        [
            'scope_type' => ScopeType::SPECIFIC_DEPARTMENT->value,
            'scope_department_id' => $this->dept->public_id,
        ],
        $this->adminUser
    );

    expect($grant)
        ->scope_department_id->toBe($this->dept->id)
        ->revoked_at->toBeNull();
});

it('revokes a monitoring scope grant', function () {
    $grant = $this->service->grant(
        $this->user,
        ['scope_type' => ScopeType::TENANT->value],
        $this->adminUser
    );

    $revoked = $this->service->revoke($grant);
    expect($revoked->revoked_at)->not->toBeNull();
});
