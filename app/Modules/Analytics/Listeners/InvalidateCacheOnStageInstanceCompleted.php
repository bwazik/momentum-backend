<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\StageInstanceCompleted;

class InvalidateCacheOnStageInstanceCompleted
{
    use InvalidatesAnalyticsCache;

    public function handle(StageInstanceCompleted $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
