<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageAssignmentOverridden implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageAssignment $oldAssignment,
        public TaskStageAssignment $newAssignment,
        public string $reason,
    ) {}
}
