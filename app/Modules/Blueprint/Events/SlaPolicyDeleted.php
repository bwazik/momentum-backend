<?php

namespace App\Modules\Blueprint\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SlaPolicyDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $slaPolicy) {}
}
