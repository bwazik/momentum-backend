<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\SubStageInstanceCompleted;

class InvalidateCacheOnSubStageInstanceCompleted
{
    use InvalidatesAnalyticsCache;

    public function handle(SubStageInstanceCompleted $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
