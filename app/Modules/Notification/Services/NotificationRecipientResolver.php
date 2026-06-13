<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    public function activeStageAssignees(TaskStageInstance $stageInstance): Collection
    {
        return $stageInstance->assignments()
            ->where('is_completed', false)
            ->whereNull('reassigned_at')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn ($u) => $u && $u->is_active)
            ->unique('id')
            ->values();
    }

    public function activeTaskParticipants(Task $task): Collection
    {
        $assigneeIds = TaskStageAssignment::where('task_id', $task->id)
            ->where('is_completed', false)
            ->whereNull('reassigned_at')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn ($u) => $u && $u->is_active)
            ->unique('id')
            ->values();

        $initiator = $this->initiator($task);
        if ($initiator && $assigneeIds->doesntContain(fn ($u) => $u->id === $initiator->id)) {
            $assigneeIds->push($initiator);
        }

        return $assigneeIds->unique('id')->values();
    }

    public function initiator(Task $task): ?User
    {
        return $task->initiator;
    }
}
