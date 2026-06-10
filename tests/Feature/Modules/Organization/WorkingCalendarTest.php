<?php

use App\Modules\Organization\Exceptions\CannotDeleteDefaultCalendarException;
use App\Modules\Organization\Models\PublicHoliday;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Services\CalendarService;
use App\Modules\Organization\Services\WorkingDayCalculator;
use App\Services\Platform\TenantProvisioningService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Org Cal Test',
        'name_ar' => 'اختبار',
        'slug' => 'org-cal-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->service = app(CalendarService::class);
    $this->calculator = app(WorkingDayCalculator::class);
});

afterEach(function () {
    PublicHoliday::whereNotNull('id')->delete();
    WorkingCalendar::whereNotNull('id')->delete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('creates a working calendar', function () {
    $calendar = $this->service->createCalendar([
        'name_ar' => 'التقويم الافتراضي',
        'name_en' => 'Default Calendar',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
        'is_default' => true,
    ]);

    expect($calendar)
        ->name_ar->toBe('التقويم الافتراضي')
        ->working_days->toBe('0,1,2,3,4')
        ->is_default->toBeTrue()
        ->public_id->not->toBeEmpty();
});

it('toggles default calendar so only one default exists at a time', function () {
    $cal1 = $this->service->createCalendar([
        'name_ar' => 'الأول',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
        'is_default' => true,
    ]);

    $cal2 = $this->service->createCalendar([
        'name_ar' => 'الثاني',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '09:00',
        'working_hours_end' => '17:00',
        'is_default' => true,
    ]);

    expect($cal1->fresh()->is_default)->toBeFalse()
        ->and($cal2->fresh()->is_default)->toBeTrue();
});

it('prevents deleting the default calendar', function () {
    $calendar = $this->service->createCalendar([
        'name_ar' => 'افتراضي',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
        'is_default' => true,
    ]);

    $this->service->deleteCalendar($calendar);
})->throws(CannotDeleteDefaultCalendarException::class);

it('deletes a non-default calendar', function () {
    $calendar = $this->service->createCalendar([
        'name_ar' => 'غير افتراضي',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
    ]);

    $this->service->deleteCalendar($calendar);

    expect(WorkingCalendar::where('public_id', $calendar->public_id)->exists())->toBeFalse();
});

it('creates a public holiday for a calendar', function () {
    $calendar = $this->service->createCalendar([
        'name_ar' => 'تقويم',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
    ]);

    $holiday = $this->service->createHoliday($calendar, [
        'name_ar' => 'العيد الوطني',
        'name_en' => 'National Day',
        'holiday_date' => '2026-09-23',
        'is_recurring' => true,
    ]);

    expect($holiday)
        ->name_ar->toBe('العيد الوطني')
        ->holiday_date->toDateString()->toBe('2026-09-23')
        ->is_recurring->toBeTrue();
});

it('enforces unique holiday per calendar per date', function () {
    $calendar = $this->service->createCalendar([
        'name_ar' => 'تقويم',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
    ]);

    $this->service->createHoliday($calendar, [
        'name_ar' => 'عطلة ١',
        'holiday_date' => '2026-06-15',
    ]);

    $this->service->createHoliday($calendar, [
        'name_ar' => 'عطلة ٢',
        'holiday_date' => '2026-06-15',
    ]);
})->throws(QueryException::class);

it('calculates isWorkingDay correctly excluding weekends and holidays', function () {
    $calendar = $this->service->createCalendar([
        'name_ar' => 'تقويم',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
    ]);

    $this->service->createHoliday($calendar, [
        'name_ar' => 'عطلة',
        'holiday_date' => '2026-06-15',
    ]);

    expect($this->calculator->isWorkingDay($calendar, Carbon::parse('2026-06-14')))->toBeTrue()
        ->and($this->calculator->isWorkingDay($calendar, Carbon::parse('2026-06-15')))->toBeFalse()
        ->and($this->calculator->isWorkingDay($calendar, Carbon::parse('2026-06-16')))->toBeTrue()
        ->and($this->calculator->isWorkingDay($calendar, Carbon::parse('2026-06-13')))->toBeFalse();
});

it('adds working days correctly skipping non-working days and holidays', function () {
    $calendar = $this->service->createCalendar([
        'name_ar' => 'تقويم',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
    ]);

    $this->service->createHoliday($calendar, [
        'name_ar' => 'عطلة',
        'holiday_date' => '2026-06-17',
    ]);

    $result = $this->calculator->addWorkingDays($calendar, Carbon::parse('2026-06-14'), 3);

    expect($result->toDateString())->toBe('2026-06-18');
});

it('checks recurring holidays', function () {
    $calendar = $this->service->createCalendar([
        'name_ar' => 'تقويم',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
    ]);

    $this->service->createHoliday($calendar, [
        'name_ar' => 'عيد متكرر',
        'holiday_date' => '2026-09-23',
        'is_recurring' => true,
    ]);

    expect($this->calculator->isWorkingDay($calendar, Carbon::parse('2027-09-23')))->toBeFalse();
});
