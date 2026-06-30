<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Exceptions\DuplicateGrantException;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Services\GrantService;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM PosGrant Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-posgrant-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);

    $this->service = app(GrantService::class);
    $this->adminUser = User::factory()->tenantAdmin()->create();
    $this->dept = Department::factory()->create();
    $this->grade = AuthorityGrade::factory()->create(['rank' => 1]);
    $this->position = Position::factory()->create([
        'department_id' => $this->dept->id,
        'authority_grade_id' => $this->grade->id,
    ]);
    $this->capability = Capability::where('key', 'task.view.organization')->first();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('grants a capability to a position', function () {
    $grant = $this->service->grantToPosition(
        $this->position,
        ['capability_id' => $this->capability->public_id, 'scope_type' => ScopeType::TENANT->value],
        $this->adminUser
    );

    expect($grant)
        ->position_id->toBe($this->position->id)
        ->capability_id->toBe($this->capability->id)
        ->revoked_at->toBeNull();
});

it('revokes a position capability grant', function () {
    $grant = $this->service->grantToPosition(
        $this->position,
        ['capability_id' => $this->capability->public_id, 'scope_type' => ScopeType::TENANT->value],
        $this->adminUser
    );

    $revoked = $this->service->revokePositionGrant($grant);
    expect($revoked->revoked_at)->not->toBeNull();
});

it('prevents duplicate active position grants', function () {
    $this->service->grantToPosition(
        $this->position,
        ['capability_id' => $this->capability->public_id, 'scope_type' => ScopeType::TENANT->value],
        $this->adminUser
    );

    $this->expectException(DuplicateGrantException::class);

    $this->service->grantToPosition(
        $this->position,
        ['capability_id' => $this->capability->public_id, 'scope_type' => ScopeType::TENANT->value],
        $this->adminUser
    );
});
