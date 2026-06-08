<?php

namespace App\Modules\Organization\Events;

use App\Modules\Organization\Models\WorkingCalendar;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkingCalendarCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public WorkingCalendar $calendar) {}
}
