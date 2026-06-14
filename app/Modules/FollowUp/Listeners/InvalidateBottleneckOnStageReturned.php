<?php

namespace App\Modules\FollowUp\Listeners;

use App\Modules\FollowUp\Listeners\Concerns\InvalidatesFollowUpBottleneckCache;
use App\Modules\Task\Events\StageInstanceReturned;

class InvalidateBottleneckOnStageReturned
{
    use InvalidatesFollowUpBottleneckCache;

    public function handle(StageInstanceReturned $event): void
    {
        $this->invalidateBottleneckCache();
    }
}
