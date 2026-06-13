<?php

namespace App\Modules\Analytics\Listeners\Concerns;

use Illuminate\Support\Facades\Cache;

trait InvalidatesAnalyticsCache
{
    protected function invalidateAnalyticsCache(): void
    {
        $slug = tenant()->slug;

        foreach (['executive_summary', 'department'] as $group) {
            $listKey = "{$slug}:analytics:keys:{$group}";
            $keys = Cache::get($listKey, []);

            foreach ($keys as $key) {
                Cache::forget($key);
            }

            Cache::forget($listKey);
        }
    }
}
