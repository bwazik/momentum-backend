<?php

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Notifications\TaskResumedNotification;
use App\Modules\Notification\Services\NotificationRecipientResolver;
use App\Modules\Task\Events\TaskResumed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendTaskResumedNotification
{
    public function __construct(private NotificationRecipientResolver $resolver) {}

    public function handle(TaskResumed $event): void
    {
        try {
            $task = $event->task;

            if (! $task->relationLoaded('initiator')) {
                $task->load('initiator');
            }

            $participants = $this->resolver->activeTaskParticipants($task);

            if ($participants->isEmpty()) {
                return;
            }

            DB::transaction(function () use ($participants, $task) {
                foreach ($participants as $user) {
                    $dedupe = 'task_resumed:'.$task->public_id.':'.$user->public_id;

                    if ($this->alreadyNotified($user, $dedupe)) {
                        continue;
                    }

                    $user->notify(new TaskResumedNotification(
                        taskPublicId: $task->public_id,
                        taskTitleAr: $task->title_ar,
                        taskTitleEn: $task->title_en,
                        dedupeKey: $dedupe,
                    ));

                    Log::channel('notification')->info('Task resumed notification dispatched', [
                        'tenant_slug' => tenant()?->slug ?? 'central',
                        'action' => 'notification.create',
                        'entity_type' => 'notification',
                        'entity_id' => $dedupe,
                        'performed_by' => 'system',
                        'source_event' => 'TaskResumed',
                        'recipient' => $user->public_id,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send task resumed notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'TaskResumed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', TaskResumedNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
