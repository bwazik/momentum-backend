<?php

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Notifications\TaskAdvancedNotification;
use App\Modules\Task\Events\StageInstanceAdvanced;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendTaskAdvancedNotification
{
    public function handle(StageInstanceAdvanced $event): void
    {
        try {
            $completedStage = $event->completedStageInstance->loadMissing(['task', 'blueprintStage', 'assignments.user']);

            $task = $completedStage->task;
            $blueprintStage = $completedStage->blueprintStage;

            if (! $task || ! $blueprintStage) {
                return;
            }

            $completedAssignees = $completedStage->assignments->filter(fn ($a) => $a->is_completed && $a->user);

            DB::transaction(function () use ($completedAssignees, $completedStage, $task, $blueprintStage) {
                foreach ($completedAssignees as $assignment) {
                    $user = $assignment->user;
                    if (! $user->is_active) {
                        continue;
                    }

                    $dedupe = 'task_advanced:'.$completedStage->id.':'.$user->public_id;

                    if ($this->alreadyNotified($user, $dedupe)) {
                        continue;
                    }

                    $user->notify(new TaskAdvancedNotification(
                        taskPublicId: $task->public_id,
                        taskTitleAr: $task->title_ar,
                        taskTitleEn: $task->title_en,
                        stagePublicId: (string) $completedStage->id,
                        stageNameAr: $blueprintStage->name_ar,
                        stageNameEn: $blueprintStage->name_en,
                        dedupeKey: $dedupe,
                    ));

                    Log::channel('notification')->info('Task advanced notification dispatched', [
                        'tenant_slug' => tenant()?->slug ?? 'central',
                        'action' => 'notification.create',
                        'entity_type' => 'notification',
                        'entity_id' => $dedupe,
                        'performed_by' => 'system',
                        'source_event' => 'StageInstanceAdvanced',
                        'recipient' => $user->public_id,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send task advanced notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'StageInstanceAdvanced',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', TaskAdvancedNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
