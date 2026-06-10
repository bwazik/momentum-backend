<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Events\PublicHolidayCreated;
use App\Modules\Organization\Events\PublicHolidayDeleted;
use App\Modules\Organization\Events\WorkingCalendarCreated;
use App\Modules\Organization\Events\WorkingCalendarUpdated;
use App\Modules\Organization\Exceptions\CannotDeleteDefaultCalendarException;
use App\Modules\Organization\Models\PublicHoliday;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Traits\AuthenticatedUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalendarService
{
    use AuthenticatedUser;

    public function createCalendar(array $data): WorkingCalendar
    {
        try {
            return DB::transaction(function () use ($data) {
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
            });
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to create calendar', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'calendar.create',
                'entity_type' => 'working_calendar',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function updateCalendar(WorkingCalendar $calendar, array $data): WorkingCalendar
    {
        try {
            return DB::transaction(function () use ($calendar, $data) {
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
            });
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to update calendar', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'calendar.update',
                'entity_type' => 'working_calendar',
                'entity_id' => $calendar->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deleteCalendar(WorkingCalendar $calendar): void
    {
        try {
            if ($calendar->is_default) {
                throw new CannotDeleteDefaultCalendarException;
            }

            $calendar->delete();
        } catch (CannotDeleteDefaultCalendarException $e) {
            Log::channel('organization')->warning('Attempted to delete default calendar', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'calendar.delete',
                'entity_type' => 'working_calendar',
                'entity_id' => $calendar->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to delete calendar', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'calendar.delete',
                'entity_type' => 'working_calendar',
                'entity_id' => $calendar->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function listCalendars(): Collection
    {
        return WorkingCalendar::orderBy('name_ar')->get();
    }

    public function createHoliday(WorkingCalendar $calendar, array $data): PublicHoliday
    {
        try {
            $holiday = $calendar->holidays()->create([
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'holiday_date' => $data['holiday_date'],
                'is_recurring' => $data['is_recurring'] ?? false,
            ]);

            event(new PublicHolidayCreated($holiday));

            return $holiday;
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to create holiday', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'holiday.create',
                'entity_type' => 'public_holiday',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function updateHoliday(PublicHoliday $holiday, array $data): PublicHoliday
    {
        try {
            if (array_key_exists('name_en', $data) && empty($data['name_en'])) {
                $data['name_en'] = $data['name_ar'] ?? $holiday->name_ar;
            }

            $holiday->update($data);

            return $holiday->fresh();
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to update holiday', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'holiday.update',
                'entity_type' => 'public_holiday',
                'entity_id' => $holiday->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deleteHoliday(PublicHoliday $holiday): void
    {
        try {
            $holiday->delete();

            event(new PublicHolidayDeleted($holiday));
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to delete holiday', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'holiday.delete',
                'entity_type' => 'public_holiday',
                'entity_id' => $holiday->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
