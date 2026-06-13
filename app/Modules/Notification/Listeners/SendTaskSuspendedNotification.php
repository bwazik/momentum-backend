<?php

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Notifications\TaskSuspendedNotification;
use App\Modules\Notification\Services\NotificationRecipientResolver;
use App\Modules\Task\Events\TaskSuspended;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendTaskSuspendedNotification
{
    public function __construct(private NotificationRecipientResolver $resolver) {}

    public function handle(TaskSuspended $event): void
    {
        try {
            $task = $event->task;

            if (! $task->relationLoaded('initiator')) {
                $task->load('initiator');
            }

            $participants = $this->resolver->activeTaskParticipants($task);
            $reason = $event->reason;

            if ($participants->isEmpty()) {
                return;
            }

            DB::transaction(function () use ($participants, $task, $reason) {
                foreach ($participants as $user) {
                    $dedupe = 'task_suspended:'.$task->public_id.':'.$user->public_id;

                    if ($this->alreadyNotified($user, $dedupe)) {
                        continue;
                    }

                    $user->notify(new TaskSuspendedNotification(
                        taskPublicId: $task->public_id,
                        taskTitleAr: $task->title_ar,
                        taskTitleEn: $task->title_en,
                        reason: $reason,
                        dedupeKey: $dedupe,
                    ));

                    Log::channel('notification')->info('Task suspended notification dispatched', [
                        'tenant_slug' => tenant()?->slug ?? 'central',
                        'action' => 'notification.create',
                        'entity_type' => 'notification',
                        'entity_id' => $dedupe,
                        'performed_by' => 'system',
                        'source_event' => 'TaskSuspended',
                        'recipient' => $user->public_id,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send task suspended notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'TaskSuspended',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', TaskSuspendedNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
