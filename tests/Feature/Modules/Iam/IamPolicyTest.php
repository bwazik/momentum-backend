<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\PositionCapabilityGrant;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Iam\Services\IamPolicy;
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
        'name_en' => 'IAM Policy Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-policy-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);

    $this->policy = app(IamPolicy::class);
    $this->adminUser = User::factory()->tenantAdmin()->create();
    $this->user = User::factory()->create();
    $this->dept = Department::factory()->create();
    $this->grade = AuthorityGrade::factory()->create(['rank' => 1]);
    $this->position = Position::factory()->create([
        'department_id' => $this->dept->id,
        'authority_grade_id' => $this->grade->id,
    ]);
    $this->capability = Capability::where('key', 'task.view.organization')->first();
});

afterEach(function () {
    UserPositionAssignment::whereNotNull('id')->delete();
    PositionCapabilityGrant::whereNotNull('id')->delete();
    UserCapabilityGrant::whereNotNull('id')->delete();
    Position::whereNotNull('id')->forceDelete();
    AuthorityGrade::whereNotNull('id')->delete();
    Department::whereNotNull('id')->delete();
    Capability::whereNotNull('id')->delete();
    User::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('grants access when user has capability via position grant', function () {
    UserPositionAssignment::create([
        'user_id' => $this->user->id,
        'position_id' => $this->position->id,
        'started_at' => now(),
        'is_primary' => true,
    ]);

    PositionCapabilityGrant::create([
        'position_id' => $this->position->id,
        'capability_id' => $this->capability->id,
        'scope_type' => ScopeType::TENANT->value,
        'granted_by_user_id' => $this->adminUser->id,
        'granted_at' => now(),
    ]);

    expect($this->policy->check($this->user, 'task.view.organization'))->toBeTrue();
});

it('denies access when user lacks capability', function () {
    expect($this->policy->check($this->user, 'task.view.organization'))->toBeFalse();
});

it('grants access when user has capability via direct grant', function () {
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $this->capability->id,
        'scope_type' => ScopeType::TENANT->value,
        'granted_by_user_id' => $this->adminUser->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    expect($this->policy->check($this->user, 'task.view.organization'))->toBeTrue();
});

it('denies access after grant is revoked', function () {
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $this->capability->id,
        'scope_type' => ScopeType::TENANT->value,
        'granted_by_user_id' => $this->adminUser->id,
        'granted_at' => now(),
        'revoked_at' => now(),
        'reason' => 'Revoked test grant',
    ]);

    expect($this->policy->check($this->user, 'task.view.organization'))->toBeFalse();
});

it('resolves department_tree scope correctly', function () {
    $parent = Department::factory()->create();
    $child = Department::factory()->create(['parent_department_id' => $parent->id]);
    $grandchild = Department::factory()->create(['parent_department_id' => $child->id]);

    $user = User::factory()->create();
    $pos = Position::factory()->create([
        'department_id' => $grandchild->id,
        'authority_grade_id' => $this->grade->id,
    ]);

    UserPositionAssignment::create([
        'user_id' => $user->id,
        'position_id' => $pos->id,
        'started_at' => now(),
        'is_primary' => true,
    ]);

    PositionCapabilityGrant::create([
        'position_id' => $pos->id,
        'capability_id' => $this->capability->id,
        'scope_type' => ScopeType::DEPARTMENT_TREE->value,
        'scope_department_id' => $parent->id,
        'granted_by_user_id' => $this->adminUser->id,
        'granted_at' => now(),
    ]);

    expect($this->policy->check($user, 'task.view.organization', ScopeType::DEPARTMENT_TREE, $child->id))->toBeTrue();
    expect($this->policy->check($user, 'task.view.organization', ScopeType::DEPARTMENT_TREE, $grandchild->id))->toBeTrue();
});

it('returns false for isOutOfOffice when not OOO', function () {
    expect($this->policy->isOutOfOffice($this->user))->toBeFalse();
});

it('returns true for isOutOfOffice when OOO', function () {
    $this->user->update(['is_out_of_office' => true]);
    expect($this->policy->isOutOfOffice($this->user))->toBeTrue();
});
