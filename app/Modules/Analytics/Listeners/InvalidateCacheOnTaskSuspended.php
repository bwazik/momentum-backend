<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\TaskSuspended;

class InvalidateCacheOnTaskSuspended
{
    use InvalidatesAnalyticsCache;

    public function handle(TaskSuspended $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
