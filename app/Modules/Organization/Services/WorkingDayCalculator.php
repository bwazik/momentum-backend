<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\WorkingCalendar;
use Carbon\Carbon;

class WorkingDayCalculator
{
    public function isWorkingDay(WorkingCalendar $calendar, Carbon $date): bool
    {
        $workingDays = array_map('intval', explode(',', $calendar->working_days));
        $dayOfWeek = (int) $date->dayOfWeek;

        if (! in_array($dayOfWeek, $workingDays)) {
            return false;
        }

        $holidays = $calendar->holidays;
        $dateString = $date->toDateString();

        foreach ($holidays as $holiday) {
            $holidayDateString = $holiday->holiday_date->toDateString();
            if ($holidayDateString === $dateString) {
                return false;
            }
            if ($holiday->is_recurring && $holiday->holiday_date->month === $date->month && $holiday->holiday_date->day === $date->day) {
                return false;
            }
        }

        return true;
    }

    public function nextWorkingDay(WorkingCalendar $calendar, Carbon $fromDate): Carbon
    {
        $date = $fromDate->copy()->addDay();

        while (! $this->isWorkingDay($calendar, $date)) {
            $date->addDay();
        }

        return $date;
    }

    public function addWorkingDays(WorkingCalendar $calendar, Carbon $fromDate, int $days): Carbon
    {
        $date = $fromDate->copy();

        for ($i = 0; $i < $days; $i++) {
            $date = $this->nextWorkingDay($calendar, $date);
        }

        return $date;
    }

    public function isWorkingTime(WorkingCalendar $calendar, Carbon $datetime): bool
    {
        if (! $this->isWorkingDay($calendar, $datetime)) {
            return false;
        }

        $time = $datetime->format('H:i:s');
        $start = Carbon::createFromTimeString($calendar->working_hours_start)->format('H:i:s');
        $end = Carbon::createFromTimeString($calendar->working_hours_end)->format('H:i:s');

        return $time >= $start && $time <= $end;
    }
}
