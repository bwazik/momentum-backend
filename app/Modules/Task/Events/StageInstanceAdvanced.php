<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageInstanceAdvanced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageInstance $completedStageInstance,
        public TaskStageInstance $newStageInstance,
    ) {}
}
