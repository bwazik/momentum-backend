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

    public function addWorkingHours(WorkingCalendar $calendar, Carbon $fromDatetime, int $hours): Carbon
    {
        return $this->addWorkingSeconds($calendar, $fromDatetime, $hours * 3600);
    }

    public function addWorkingSeconds(WorkingCalendar $calendar, Carbon $fromDatetime, int $seconds): Carbon
    {
        $remaining = $seconds;
        $current = $fromDatetime->copy();

        $start = $calendar->working_hours_start;
        $end = $calendar->working_hours_end;
        $startTime = Carbon::createFromTimeString($start);
        $endTime = Carbon::createFromTimeString($end);
        $dailySeconds = $startTime->diffInSeconds($endTime, true);

        while ($remaining > 0) {
            if (! $this->isWorkingDay($calendar, $current)) {
                $current->addDay()->setTimeFromTimeString($calendar->working_hours_start);

                continue;
            }

            $currentTime = $current->format('H:i:s');
            $endTime = $calendar->working_hours_end;

            if ($currentTime < $calendar->working_hours_start) {
                $current->setTimeFromTimeString($calendar->working_hours_start);
                $currentTime = $calendar->working_hours_start;
            }

            if ($currentTime >= $endTime) {
                $current->addDay()->setTimeFromTimeString($calendar->working_hours_start);

                continue;
            }

            $secondsLeftInDay = Carbon::createFromTimeString($currentTime)->diffInSeconds(Carbon::createFromTimeString($endTime), true);

            if ($remaining <= $secondsLeftInDay) {
                $current->addSeconds($remaining);
                $remaining = 0;
            } else {
                $remaining -= $secondsLeftInDay;
                $current->addDay()->setTimeFromTimeString($calendar->working_hours_start);
            }
        }

        return $current;
    }

    public function workingSecondsBetween(WorkingCalendar $calendar, Carbon $from, Carbon $to): int
    {
        if ($from->gte($to)) {
            return 0;
        }

        $totalSeconds = 0;
        $current = $from->copy();
        $start = $calendar->working_hours_start;
        $end = $calendar->working_hours_end;

        while ($current->lt($to)) {
            if (! $this->isWorkingDay($calendar, $current)) {
                $current->addDay()->startOfDay();

                continue;
            }

            $dayStart = $current->copy()->setTimeFromTimeString($start);
            $dayEnd = $current->copy()->setTimeFromTimeString($end);

            if ($current->gte($dayEnd)) {
                $current->addDay()->setTimeFromTimeString($start);

                continue;
            }

            $effectiveStart = $current->gt($dayStart) ? $current->copy() : $dayStart->copy();
            $effectiveEnd = $to->lt($dayEnd) ? $to->copy() : $dayEnd->copy();

            if ($effectiveStart->lt($effectiveEnd)) {
                $totalSeconds += $effectiveStart->diffInSeconds($effectiveEnd, true);
            }

            $current = $dayEnd->copy()->addDay()->setTimeFromTimeString($start);
        }

        return $totalSeconds;
    }
}
