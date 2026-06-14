<?php

namespace App\Modules\FollowUp\Listeners;

use App\Modules\FollowUp\Listeners\Concerns\InvalidatesFollowUpBottleneckCache;
use App\Modules\Task\Events\StageInstanceAdvanced;

class InvalidateBottleneckOnStageAdvanced
{
    use InvalidatesFollowUpBottleneckCache;

    public function handle(StageInstanceAdvanced $event): void
    {
        $this->invalidateBottleneckCache();
    }
}
