<?php

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Notifications\TaskReturnedNotification;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Events\StageInstanceReturned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendTaskReturnedNotification
{
    public function handle(StageInstanceReturned $event): void
    {
        try {
            $task = $event->returnedStageInstance->task;

            if (! $task) {
                return;
            }

            $task->loadMissing(['stageInstances.blueprintStage', 'stageInstances.assignments.user']);

            $activeStageInstances = $task->stageInstances->filter(
                fn ($si) => $si->status === StageInstanceStatus::Active
            );

            DB::transaction(function () use ($activeStageInstances, $task) {
                foreach ($activeStageInstances as $stageInstance) {
                    $blueprintStage = $stageInstance->blueprintStage;
                    if (! $blueprintStage) {
                        continue;
                    }

                    $assignees = $stageInstance->assignments->filter(fn ($a) => ! $a->is_completed && ! $a->reassigned_at);

                    foreach ($assignees as $assignment) {
                        $user = $assignment->user;
                        if (! $user || ! $user->is_active) {
                            continue;
                        }

                        $dedupe = 'task_returned:'.$stageInstance->id.':'.$user->public_id;

                        if ($this->alreadyNotified($user, $dedupe)) {
                            continue;
                        }

                        $user->notify(new TaskReturnedNotification(
                            taskPublicId: $task->public_id,
                            taskTitleAr: $task->title_ar,
                            taskTitleEn: $task->title_en,
                            stagePublicId: (string) $stageInstance->id,
                            stageNameAr: $blueprintStage->name_ar,
                            stageNameEn: $blueprintStage->name_en,
                            dedupeKey: $dedupe,
                        ));

                        Log::channel('notification')->info('Task returned notification dispatched', [
                            'tenant_slug' => tenant()?->slug ?? 'central',
                            'action' => 'notification.create',
                            'entity_type' => 'notification',
                            'entity_id' => $dedupe,
                            'performed_by' => 'system',
                            'source_event' => 'StageInstanceReturned',
                            'recipient' => $user->public_id,
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send task returned notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'StageInstanceReturned',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', TaskReturnedNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
