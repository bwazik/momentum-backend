<?php

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Notifications\StageAssignmentReceivedNotification;
use App\Modules\Task\Events\StageAssignmentCreated;
use Illuminate\Support\Facades\Log;

class SendStageAssignmentNotification
{
    public function handle(StageAssignmentCreated $event): void
    {
        try {
            $assignment = $event->assignment->loadMissing([
                'user',
                'stageInstance.task',
                'stageInstance.blueprintStage',
                'subStageInstance.parentStageInstance.task',
                'subStageInstance.parentStageInstance.blueprintStage',
            ]);

            if (! $assignment->user || ! $assignment->user->is_active) {
                return;
            }

            if ($assignment->stageInstance) {
                $task = $assignment->stageInstance->task;
                $stage = $assignment->stageInstance->blueprintStage;
                $stageIdentifier = $assignment->stageInstance->id;
            } elseif ($assignment->subStageInstance?->parentStageInstance) {
                $task = $assignment->subStageInstance->parentStageInstance->task;
                $stage = $assignment->subStageInstance->parentStageInstance->blueprintStage;
                $stageIdentifier = $assignment->subStageInstance->id;
            } else {
                return;
            }

            if (! $task || ! $stage) {
                return;
            }

            $dedupe = 'stage_assignment_received:'.$stageIdentifier.':'.$assignment->user->public_id;

            if ($this->alreadyNotified($assignment->user, $dedupe)) {
                return;
            }

            $assignment->user->notify(new StageAssignmentReceivedNotification(
                taskPublicId: $task->public_id,
                taskTitleAr: $task->title_ar,
                taskTitleEn: $task->title_en,
                stagePublicId: (string) $stageIdentifier,
                stageNameAr: $stage->name_ar,
                stageNameEn: $stage->name_en,
                dedupeKey: $dedupe,
            ));

            Log::channel('notification')->info('Stage assignment notification dispatched', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'entity_type' => 'notification',
                'entity_id' => $dedupe,
                'performed_by' => 'system',
                'source_event' => 'StageAssignmentCreated',
                'recipient' => $assignment->user->public_id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send stage assignment notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'StageAssignmentCreated',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', StageAssignmentReceivedNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
