<?php

namespace App\Modules\Organization\Events;

use App\Modules\Organization\Models\PublicHoliday;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PublicHolidayDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PublicHoliday $publicHoliday) {}
}
