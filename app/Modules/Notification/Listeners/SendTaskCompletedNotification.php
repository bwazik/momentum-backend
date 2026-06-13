<?php

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Notifications\TaskCompletedNotification;
use App\Modules\Task\Events\TaskCompleted;
use Illuminate\Support\Facades\Log;

class SendTaskCompletedNotification
{
    public function handle(TaskCompleted $event): void
    {
        try {
            $task = $event->task;

            if (! $task->relationLoaded('initiator')) {
                $task->load('initiator');
            }

            $initiator = $task->initiator;

            if (! $initiator || ! $initiator->is_active) {
                return;
            }

            $dedupe = 'task_completed:'.$task->public_id.':'.$initiator->public_id;

            if ($this->alreadyNotified($initiator, $dedupe)) {
                return;
            }

            $initiator->notify(new TaskCompletedNotification(
                taskPublicId: $task->public_id,
                taskTitleAr: $task->title_ar,
                taskTitleEn: $task->title_en,
                dedupeKey: $dedupe,
            ));

            Log::channel('notification')->info('Task completed notification dispatched', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'entity_type' => 'notification',
                'entity_id' => $dedupe,
                'performed_by' => 'system',
                'source_event' => 'TaskCompleted',
                'recipient' => $initiator->public_id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send task completed notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'TaskCompleted',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', TaskCompletedNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
