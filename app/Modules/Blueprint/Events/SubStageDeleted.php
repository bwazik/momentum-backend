<?php

namespace App\Modules\Blueprint\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SubStageDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $subStage) {}
}
