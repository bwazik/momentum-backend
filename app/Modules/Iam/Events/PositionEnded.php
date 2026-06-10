<?php

namespace App\Modules\Iam\Events;

use App\Modules\Iam\Models\UserPositionAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PositionEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public UserPositionAssignment $assignment) {}
}
