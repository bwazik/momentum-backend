<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskSubStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SubStageInstanceCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskSubStageInstance $subStageInstance) {}
}
