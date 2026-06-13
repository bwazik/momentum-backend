<?php

namespace App\Modules\Tracking\Events;

use App\Modules\Tracking\Models\Escalation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EscalationResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Escalation $escalation) {}
}
