<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TaskViewed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public User $user,
    ) {}
}
