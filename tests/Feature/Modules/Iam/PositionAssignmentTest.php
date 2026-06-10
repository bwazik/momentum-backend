<?php

use App\Models\User;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Iam\Services\PositionAssignmentService;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM Position Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-pos-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->service = app(PositionAssignmentService::class);
    $this->user = User::factory()->create();
    $this->dept = Department::factory()->create();
    $this->grade = AuthorityGrade::factory()->create(['rank' => 1]);
    $this->position = Position::factory()->create([
        'department_id' => $this->dept->id,
        'authority_grade_id' => $this->grade->id,
    ]);
});

afterEach(function () {
    UserPositionAssignment::whereNotNull('id')->delete();
    Position::whereNotNull('id')->forceDelete();
    AuthorityGrade::whereNotNull('id')->delete();
    Department::whereNotNull('id')->delete();
    User::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('assigns a position to a user', function () {
    $assignment = $this->service->assign($this->user, $this->position->public_id);

    expect($assignment)
        ->user_id->toBe($this->user->id)
        ->position_id->toBe($this->position->id)
        ->is_primary->toBeTrue()
        ->ended_at->toBeNull();
});

it('allows only one primary position per user', function () {
    $pos2 = Position::factory()->create([
        'department_id' => $this->dept->id,
        'authority_grade_id' => $this->grade->id,
    ]);

    $assignment1 = $this->service->assign($this->user, $this->position->public_id);
    expect($assignment1->is_primary)->toBeTrue();

    $assignment2 = $this->service->assign($this->user, $pos2->public_id);
    expect($assignment2->is_primary)->toBeTrue();
    expect($assignment1->fresh()->is_primary)->toBeFalse();
});

it('ends a position assignment', function () {
    $assignment = $this->service->assign($this->user, $this->position->public_id);

    $ended = $this->service->end($assignment);
    expect($ended->ended_at)->not->toBeNull();
});

it('sets a position as primary', function () {
    $pos2 = Position::factory()->create([
        'department_id' => $this->dept->id,
        'authority_grade_id' => $this->grade->id,
    ]);

    $assignment1 = $this->service->assign($this->user, $this->position->public_id, ['is_primary' => true]);
    $assignment2 = $this->service->assign($this->user, $pos2->public_id, ['is_primary' => false]);

    $updated = $this->service->setPrimary($assignment2);
    expect($updated->is_primary)->toBeTrue();
    expect($assignment1->fresh()->is_primary)->toBeFalse();
});
