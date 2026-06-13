<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\TaskCompleted;

class InvalidateCacheOnTaskCompleted
{
    use InvalidatesAnalyticsCache;

    public function handle(TaskCompleted $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
