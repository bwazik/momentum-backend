<?php

namespace App\Modules\FollowUp\Listeners;

use App\Modules\FollowUp\Listeners\Concerns\InvalidatesFollowUpBottleneckCache;
use App\Modules\Task\Events\SubStageInstanceCompleted;

class InvalidateBottleneckOnSubStageCompleted
{
    use InvalidatesFollowUpBottleneckCache;

    public function handle(SubStageInstanceCompleted $event): void
    {
        $this->invalidateBottleneckCache();
    }
}
