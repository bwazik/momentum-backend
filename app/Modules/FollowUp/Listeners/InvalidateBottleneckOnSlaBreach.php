<?php

namespace App\Modules\FollowUp\Listeners;

use App\Modules\FollowUp\Listeners\Concerns\InvalidatesFollowUpBottleneckCache;
use App\Modules\Tracking\Events\SlaBreached;

class InvalidateBottleneckOnSlaBreach
{
    use InvalidatesFollowUpBottleneckCache;

    public function handle(SlaBreached $event): void
    {
        $this->invalidateBottleneckCache();
    }
}
