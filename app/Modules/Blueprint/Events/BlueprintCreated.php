<?php

namespace App\Modules\Blueprint\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BlueprintCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $blueprint) {}
}
