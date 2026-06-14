<?php

namespace App\Modules\FollowUp\Listeners;

use App\Modules\FollowUp\Listeners\Concerns\InvalidatesFollowUpBottleneckCache;
use App\Modules\Task\Events\StageInstanceCompleted;

class InvalidateBottleneckOnStageCompleted
{
    use InvalidatesFollowUpBottleneckCache;

    public function handle(StageInstanceCompleted $event): void
    {
        $this->invalidateBottleneckCache();
    }
}
