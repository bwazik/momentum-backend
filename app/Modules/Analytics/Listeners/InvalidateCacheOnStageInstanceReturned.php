<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\StageInstanceReturned;

class InvalidateCacheOnStageInstanceReturned
{
    use InvalidatesAnalyticsCache;

    public function handle(StageInstanceReturned $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
