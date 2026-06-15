<?php

namespace App\Modules\Search\Services;

use App\Models\User;
use App\Modules\Search\Enums\SearchActivityType;
use App\Modules\Search\Models\UserRecentActivity;
use App\Modules\Task\Models\Task;
use Illuminate\Support\Facades\Log;

class SearchActivityService
{
    public function recordView(User $user, Task $task): void
    {
        $recent = UserRecentActivity::where('user_id', $user->id)
            ->where('task_id', $task->id)
            ->where('activity_type', SearchActivityType::TaskViewed)
            ->where('occurred_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recent) {
            return;
        }

        $this->insert($user, $task, SearchActivityType::TaskViewed);
    }

    public function recordStageCompleted(User $user, Task $task): void
    {
        $this->insert($user, $task, SearchActivityType::StageCompleted);
    }

    public function recordStageReturned(User $user, Task $task): void
    {
        $this->insert($user, $task, SearchActivityType::StageReturned);
    }

    private function insert(User $user, Task $task, SearchActivityType $type): void
    {
        try {
            UserRecentActivity::create([
                'user_id' => $user->id,
                'task_id' => $task->id,
                'activity_type' => $type,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('search')->error('Failed to record recent activity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'search.recent_activity',
                'entity_type' => 'user_recent_activity',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
