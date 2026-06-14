<?php

namespace App\Modules\FollowUp\Listeners;

use App\Modules\FollowUp\Listeners\Concerns\InvalidatesFollowUpBottleneckCache;
use App\Modules\Tracking\Events\SlaWarningTriggered;

class InvalidateBottleneckOnSlaWarning
{
    use InvalidatesFollowUpBottleneckCache;

    public function handle(SlaWarningTriggered $event): void
    {
        $this->invalidateBottleneckCache();
    }
}
