<?php

namespace App\Modules\Tracking\Events;

use App\Modules\Tracking\Models\SlaTimerInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SlaTimerStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SlaTimerInstance $timer) {}
}
