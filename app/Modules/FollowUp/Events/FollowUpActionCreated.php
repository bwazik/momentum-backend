<?php

namespace App\Modules\FollowUp\Events;

use App\Modules\FollowUp\Models\FollowUpAction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class FollowUpActionCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public FollowUpAction $action) {}
}
