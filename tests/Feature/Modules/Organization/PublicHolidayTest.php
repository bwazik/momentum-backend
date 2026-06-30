<?php

use App\Modules\Organization\Models\PublicHoliday;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Services\CalendarService;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Org Holiday Test',
        'name_ar' => 'اختبار',
        'slug' => 'org-hol-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('creates a holiday via the service', function () {
    $calendar = WorkingCalendar::create([
        'name_ar' => 'تقويم',
        'name_en' => 'Calendar',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
        'is_default' => false,
    ]);

    $service = app(CalendarService::class);
    $holiday = $service->createHoliday($calendar, [
        'name_ar' => 'عطلة',
        'holiday_date' => '2026-12-25',
        'is_recurring' => false,
    ]);

    expect($holiday->public_id)->not->toBeEmpty()
        ->and($holiday->working_calendar_id)->toBe($calendar->id);
});

it('updates a holiday', function () {
    $calendar = WorkingCalendar::create([
        'name_ar' => 'تقويم',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
        'is_default' => false,
    ]);

    $holiday = PublicHoliday::create([
        'working_calendar_id' => $calendar->id,
        'name_ar' => 'قديم',
        'holiday_date' => '2026-01-01',
        'is_recurring' => false,
    ]);

    $service = app(CalendarService::class);
    $updated = $service->updateHoliday($holiday, ['name_ar' => 'جديد']);

    expect($updated->name_ar)->toBe('جديد');
});

it('deletes a holiday', function () {
    $calendar = WorkingCalendar::create([
        'name_ar' => 'تقويم',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
        'is_default' => false,
    ]);

    $holiday = PublicHoliday::create([
        'working_calendar_id' => $calendar->id,
        'name_ar' => 'لحذف',
        'holiday_date' => '2026-03-15',
        'is_recurring' => false,
    ]);

    $service = app(CalendarService::class);
    $service->deleteHoliday($holiday);

    expect(PublicHoliday::where('public_id', $holiday->public_id)->exists())->toBeFalse();
});
