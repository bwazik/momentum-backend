<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\TaskResumed;

class InvalidateCacheOnTaskResumed
{
    use InvalidatesAnalyticsCache;

    public function handle(TaskResumed $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
