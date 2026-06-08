<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Events\PublicHolidayCreated;
use App\Modules\Organization\Events\PublicHolidayDeleted;
use App\Modules\Organization\Events\WorkingCalendarCreated;
use App\Modules\Organization\Events\WorkingCalendarUpdated;
use App\Modules\Organization\Exceptions\CannotDeleteDefaultCalendarException;
use App\Modules\Organization\Models\PublicHoliday;
use App\Modules\Organization\Models\WorkingCalendar;
use Illuminate\Database\Eloquent\Collection;

class CalendarService
{
    public function createCalendar(array $data): WorkingCalendar
    {
        if (! empty($data['is_default'])) {
            WorkingCalendar::where('is_default', true)->update(['is_default' => false]);
        }

        $calendar = WorkingCalendar::create([
            'name_ar' => $data['name_ar'],
            'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
            'working_days' => $data['working_days'],
            'working_hours_start' => $data['working_hours_start'],
            'working_hours_end' => $data['working_hours_end'],
            'timezone' => $data['timezone'] ?? 'Asia/Riyadh',
            'is_default' => $data['is_default'] ?? false,
        ]);

        event(new WorkingCalendarCreated($calendar));

        return $calendar;
    }

    public function updateCalendar(WorkingCalendar $calendar, array $data): WorkingCalendar
    {
        if (array_key_exists('name_en', $data) && empty($data['name_en'])) {
            $data['name_en'] = $data['name_ar'] ?? $calendar->name_ar;
        }

        if (! empty($data['is_default'])) {
            WorkingCalendar::where('is_default', true)
                ->where('id', '!=', $calendar->id)
                ->update(['is_default' => false]);
        }

        $calendar->update($data);

        event(new WorkingCalendarUpdated($calendar));

        return $calendar->fresh();
    }

    public function deleteCalendar(WorkingCalendar $calendar): void
    {
        if ($calendar->is_default) {
            throw new CannotDeleteDefaultCalendarException;
        }

        $calendar->delete();
    }

    public function listCalendars(): Collection
    {
        return WorkingCalendar::orderBy('name_ar')->get();
    }

    public function createHoliday(WorkingCalendar $calendar, array $data): PublicHoliday
    {
        $holiday = $calendar->holidays()->create([
            'name_ar' => $data['name_ar'],
            'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
            'holiday_date' => $data['holiday_date'],
            'is_recurring' => $data['is_recurring'] ?? false,
        ]);

        event(new PublicHolidayCreated($holiday));

        return $holiday;
    }

    public function updateHoliday(PublicHoliday $holiday, array $data): PublicHoliday
    {
        if (array_key_exists('name_en', $data) && empty($data['name_en'])) {
            $data['name_en'] = $data['name_ar'] ?? $holiday->name_ar;
        }

        $holiday->update($data);

        return $holiday->fresh();
    }

    public function deleteHoliday(PublicHoliday $holiday): void
    {
        $holiday->delete();

        event(new PublicHolidayDeleted($holiday));
    }
}
