<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\TaskCancelled;

class InvalidateCacheOnTaskCancelled
{
    use InvalidatesAnalyticsCache;

    public function handle(TaskCancelled $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
