<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Tracking\Events\SlaTimerStarted;

class InvalidateCacheOnSlaTimerStarted
{
    use InvalidatesAnalyticsCache;

    public function handle(SlaTimerStarted $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
