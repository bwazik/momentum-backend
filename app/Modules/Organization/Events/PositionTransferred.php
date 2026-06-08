<?php

namespace App\Modules\Organization\Events;

use App\Modules\Organization\Models\Position;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PositionTransferred implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Position $position) {}
}
