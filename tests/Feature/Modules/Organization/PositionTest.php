<?php

use App\Modules\Organization\Exceptions\CircularReportingLineException;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Services\PositionService;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Org Pos Test',
        'name_ar' => 'اختبار',
        'slug' => 'org-pos-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->service = app(PositionService::class);

    $this->grade = AuthorityGrade::create([
        'rank' => 1,
        'name_ar' => 'رتبة',
        'name_en' => 'Grade',
    ]);
    $this->dept = Department::create([
        'name_ar' => 'إدارة',
        'name_en' => 'Dept',
        'is_active' => true,
    ]);
});

afterEach(function () {
    Position::whereNotNull('id')->forceDelete();
    AuthorityGrade::whereNotNull('id')->delete();
    Department::whereNotNull('id')->delete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('creates a position', function () {
    $position = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'موظف',
        'title_en' => 'Employee',
        'authority_grade_id' => $this->grade->public_id,
    ]);

    expect($position)
        ->title_ar->toBe('موظف')
        ->title_en->toBe('Employee')
        ->department_id->toBe($this->dept->id)
        ->authority_grade_id->toBe($this->grade->id)
        ->is_department_head->toBeFalse()
        ->is_active->toBeTrue();
});

it('defaults title_en to title_ar when not provided', function () {
    $position = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'موظف',
        'authority_grade_id' => $this->grade->public_id,
    ]);

    expect($position->title_en)->toBe('موظف');
});

it('ensures only one department head per department', function () {
    $head1 = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'المدير الأول',
        'authority_grade_id' => $this->grade->public_id,
        'is_department_head' => true,
    ]);

    $head2 = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'المدير الثاني',
        'authority_grade_id' => $this->grade->public_id,
        'is_department_head' => true,
    ]);

    expect($head1->fresh()->is_department_head)->toBeFalse()
        ->and($head2->fresh()->is_department_head)->toBeTrue();
});

it('transfers a position to a different department', function () {
    $dept2 = Department::create([
        'name_ar' => 'إدارة أخرى',
        'name_en' => 'Other Dept',
        'is_active' => true,
    ]);

    $position = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'موظف',
        'authority_grade_id' => $this->grade->public_id,
    ]);

    $transferred = $this->service->transfer($position, $dept2->public_id);

    expect($transferred->department_id)->toBe($dept2->id);
});

it('deactivates and reactivates a position', function () {
    $position = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'موظف',
        'authority_grade_id' => $this->grade->public_id,
    ]);

    $this->service->deactivate($position);
    expect($position->fresh()->is_active)->toBeFalse();

    $this->service->reactivate($position);
    expect($position->fresh()->is_active)->toBeTrue();
});

it('prevents circular reporting lines', function () {
    $pos1 = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'أول',
        'authority_grade_id' => $this->grade->public_id,
    ]);

    $pos2 = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'ثاني',
        'authority_grade_id' => $this->grade->public_id,
        'reports_to_position_id' => $pos1->public_id,
    ]);

    $this->service->update($pos1, ['reports_to_position_id' => $pos2->public_id]);
})->throws(CircularReportingLineException::class);

it('prevents a position from reporting to itself', function () {
    $pos = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'نفسه',
        'authority_grade_id' => $this->grade->public_id,
    ]);

    $this->service->update($pos, ['reports_to_position_id' => $pos->public_id]);
})->throws(CircularReportingLineException::class);

it('soft-deletes a position', function () {
    $position = $this->service->create([
        'department_id' => $this->dept->public_id,
        'title_ar' => 'لحذف',
        'authority_grade_id' => $this->grade->public_id,
    ]);

    $this->service->delete($position);

    expect(Position::where('public_id', $position->public_id)->exists())->toBeFalse();
    expect(Position::withTrashed()->where('public_id', $position->public_id)->exists())->toBeTrue();
});
