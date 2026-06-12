<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskPriority;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TaskPriorityCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskPriority $priority) {}
}
