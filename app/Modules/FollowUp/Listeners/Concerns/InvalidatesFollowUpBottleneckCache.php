<?php

namespace App\Modules\FollowUp\Listeners\Concerns;

use Illuminate\Support\Facades\Cache;

trait InvalidatesFollowUpBottleneckCache
{
    protected function invalidateBottleneckCache(): void
    {
        Cache::forget(sprintf('%s:followup:bottlenecks', tenant()->slug));
    }
}
