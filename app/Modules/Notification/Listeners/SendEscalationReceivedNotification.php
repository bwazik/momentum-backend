<?php

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Notifications\EscalationReceivedNotification;
use App\Modules\Tracking\Events\EscalationCreated;
use Illuminate\Support\Facades\Log;

class SendEscalationReceivedNotification
{
    public function handle(EscalationCreated $event): void
    {
        try {
            $escalation = $event->escalation->loadMissing([
                'escalatedToUser',
                'task',
                'stageInstance.blueprintStage',
            ]);

            $user = $escalation->escalatedToUser;

            if (! $user || ! $user->is_active) {
                return;
            }

            $task = $escalation->task;
            $stage = $escalation->stageInstance?->blueprintStage;

            if (! $task) {
                return;
            }

            $stageNameAr = $stage?->name_ar ?? '';
            $stageNameEn = $stage?->name_en ?? null;

            $dedupe = 'escalation_received:'.$escalation->public_id.':'.$user->public_id;

            if ($this->alreadyNotified($user, $dedupe)) {
                return;
            }

            $user->notify(new EscalationReceivedNotification(
                taskPublicId: $task->public_id,
                taskTitleAr: $task->title_ar,
                taskTitleEn: $task->title_en,
                stagePublicId: $escalation->stageInstance ? (string) $escalation->stageInstance->id : null,
                escalationPublicId: $escalation->public_id,
                stageNameAr: $stageNameAr,
                stageNameEn: $stageNameEn,
                dedupeKey: $dedupe,
            ));

            Log::channel('notification')->info('Escalation received notification dispatched', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'entity_type' => 'notification',
                'entity_id' => $dedupe,
                'performed_by' => 'system',
                'source_event' => 'EscalationCreated',
                'recipient' => $user->public_id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send escalation notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'EscalationCreated',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', EscalationReceivedNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
