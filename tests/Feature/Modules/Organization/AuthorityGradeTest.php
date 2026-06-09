<?php

use App\Modules\Organization\Exceptions\AuthorityGradeHasActivePositionsException;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Services\AuthorityGradeService;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Org Grade Test',
        'name_ar' => 'اختبار',
        'slug' => 'org-grade-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->service = app(AuthorityGradeService::class);
});

afterEach(function () {
    Position::whereNotNull('id')->forceDelete();
    AuthorityGrade::whereNotNull('id')->delete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('creates an authority grade', function () {
    $grade = $this->service->create([
        'rank' => 1,
        'name_ar' => 'وزير',
        'name_en' => 'Minister',
    ]);

    expect($grade)
        ->rank->toBe(1)
        ->name_ar->toBe('وزير')
        ->name_en->toBe('Minister')
        ->public_id->not->toBeEmpty();
});

it('defaults name_en to name_ar when not provided', function () {
    $grade = $this->service->create([
        'rank' => 2,
        'name_ar' => 'وكيل وزارة',
    ]);

    expect($grade->name_en)->toBe('وكيل وزارة');
});

it('lists all authority grades ordered by rank', function () {
    $this->service->create(['rank' => 3, 'name_ar' => 'مدير']);
    $this->service->create(['rank' => 1, 'name_ar' => 'وزير']);
    $this->service->create(['rank' => 2, 'name_ar' => 'وكيل']);

    $grades = $this->service->listAll();

    expect($grades)->toHaveCount(3);
    expect($grades->first()->rank)->toBe(1);
    expect($grades->last()->rank)->toBe(3);
});

it('updates an authority grade', function () {
    $grade = $this->service->create(['rank' => 5, 'name_ar' => 'قديم']);
    $updated = $this->service->update($grade, ['name_ar' => 'جديد', 'name_en' => 'New']);

    expect($updated->name_ar)->toBe('جديد')
        ->and($updated->name_en)->toBe('New');
});

it('prevents deleting a grade referenced by active positions', function () {
    $grade = $this->service->create(['rank' => 4, 'name_ar' => 'مدير عام']);
    $dept = Department::create([
        'name_ar' => 'إدارة',
        'name_en' => 'Dept',
        'is_active' => true,
    ]);

    Position::create([
        'department_id' => $dept->id,
        'title_ar' => 'موظف',
        'authority_grade_id' => $grade->id,
        'is_active' => true,
    ]);

    $this->service->delete($grade);
})->throws(AuthorityGradeHasActivePositionsException::class);

it('deletes a grade with no active positions', function () {
    $grade = $this->service->create(['rank' => 6, 'name_ar' => 'لحذف']);

    $this->service->delete($grade);

    expect(AuthorityGrade::where('public_id', $grade->public_id)->exists())->toBeFalse();
});
