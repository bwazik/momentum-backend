<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SubStageAssignmentCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskStageAssignment $assignment) {}
}
