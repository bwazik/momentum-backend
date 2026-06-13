<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\TaskLaunched;

class InvalidateCacheOnTaskLaunched
{
    use InvalidatesAnalyticsCache;

    public function handle(TaskLaunched $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
