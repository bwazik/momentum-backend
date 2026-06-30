<?php

use App\Modules\Organization\Exceptions\CircularDepartmentReferenceException;
use App\Modules\Organization\Exceptions\DepartmentHasActivePositionsException;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Services\DepartmentService;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Org Dept Test',
        'name_ar' => 'اختبار',
        'slug' => 'org-dept-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->service = app(DepartmentService::class);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('creates a top-level department', function () {
    $dept = $this->service->create([
        'name_ar' => 'وزارة المالية',
        'name_en' => 'Ministry of Finance',
    ]);

    expect($dept)
        ->name_ar->toBe('وزارة المالية')
        ->name_en->toBe('Ministry of Finance')
        ->parent_department_id->toBeNull()
        ->is_active->toBeTrue()
        ->public_id->not->toBeEmpty();
});

it('defaults name_en to name_ar when not provided', function () {
    $dept = $this->service->create(['name_ar' => 'قطاع المالية']);

    expect($dept->name_en)->toBe('قطاع المالية');
});

it('creates a sub-department under a parent', function () {
    $parent = $this->service->create(['name_ar' => 'أم']);
    $child = $this->service->create([
        'name_ar' => 'طفل',
        'parent_department_id' => $parent->public_id,
    ]);

    expect($child->parent_department_id)->toBe($parent->id);
    expect($child->parent->public_id)->toBe($parent->public_id);
});

it('updates a department', function () {
    $dept = $this->service->create(['name_ar' => 'قديم']);
    $updated = $this->service->update($dept, ['name_ar' => 'جديد', 'name_en' => 'New']);

    expect($updated->name_ar)->toBe('جديد')
        ->and($updated->name_en)->toBe('New');
});

it('deactivates a department without cascading by default', function () {
    $parent = $this->service->create(['name_ar' => 'أم']);
    $child = $this->service->create([
        'name_ar' => 'طفل',
        'parent_department_id' => $parent->public_id,
    ]);

    $this->service->deactivate($parent);

    expect($parent->fresh()->is_active)->toBeFalse()
        ->and($child->fresh()->is_active)->toBeTrue();
});

it('deactivates a department with cascade to children', function () {
    $parent = $this->service->create(['name_ar' => 'أم']);
    $child = $this->service->create([
        'name_ar' => 'طفل',
        'parent_department_id' => $parent->public_id,
    ]);

    $this->service->deactivate($parent, cascadeToChildren: true);

    expect($parent->fresh()->is_active)->toBeFalse()
        ->and($child->fresh()->is_active)->toBeFalse();
});

it('reactivates a department', function () {
    $dept = $this->service->create(['name_ar' => '_test_']);
    $this->service->deactivate($dept);
    $this->service->reactivate($dept);

    expect($dept->fresh()->is_active)->toBeTrue();
});

it('prevents deleting a department with active positions', function () {
    $dept = $this->service->create(['name_ar' => 'إدارة']);
    $grade = AuthorityGrade::create([
        'rank' => 1,
        'name_ar' => 'رتبة',
        'name_en' => 'Grade',
    ]);

    Position::create([
        'department_id' => $dept->id,
        'title_ar' => 'موظف',
        'title_en' => 'Employee',
        'authority_grade_id' => $grade->id,
        'is_active' => true,
    ]);

    $this->service->delete($dept);
})->throws(DepartmentHasActivePositionsException::class);

it('soft-deletes a department with no active positions', function () {
    $dept = $this->service->create(['name_ar' => 'لحذف']);

    $this->service->delete($dept);

    expect(Department::where('public_id', $dept->public_id)->exists())->toBeFalse();
    expect(Department::withTrashed()->where('public_id', $dept->public_id)->exists())->toBeTrue();
});

it('returns the department tree', function () {
    $root = $this->service->create(['name_ar' => 'جذر']);
    $child = $this->service->create([
        'name_ar' => 'فرع',
        'parent_department_id' => $root->public_id,
    ]);

    $tree = $this->service->getTree();

    expect($tree)->toHaveCount(1);
    expect($tree->first()->children)->toHaveCount(1);
    expect($tree->first()->children->first()->public_id)->toBe($child->public_id);
});

it('prevents circular department references', function () {
    $parent = $this->service->create(['name_ar' => 'أم']);
    $child = $this->service->create([
        'name_ar' => 'طفل',
        'parent_department_id' => $parent->public_id,
    ]);

    $this->service->update($parent, ['parent_department_id' => $child->public_id]);
})->throws(CircularDepartmentReferenceException::class);

it('prevents a department from being its own parent', function () {
    $dept = $this->service->create(['name_ar' => 'نفسه']);

    $this->service->update($dept, ['parent_department_id' => $dept->public_id]);
})->throws(CircularDepartmentReferenceException::class);
