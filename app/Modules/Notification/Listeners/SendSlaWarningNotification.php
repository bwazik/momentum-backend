<?php

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Notifications\SlaWarningNotification;
use App\Modules\Notification\Services\NotificationRecipientResolver;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Tracking\Events\SlaWarningTriggered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendSlaWarningNotification
{
    public function __construct(private NotificationRecipientResolver $resolver) {}

    public function handle(SlaWarningTriggered $event): void
    {
        try {
            $timer = $event->timer;
            $stageId = $timer->stage_instance_id ?? $timer->subStageInstance?->parent_stage_instance_id;

            if (! $stageId) {
                return;
            }

            $stage = TaskStageInstance::with(['task', 'blueprintStage'])->find($stageId);

            if (! $stage || ! $stage->task || ! $stage->blueprintStage) {
                return;
            }

            $assignees = $this->resolver->activeStageAssignees($stage);

            if ($assignees->isEmpty()) {
                return;
            }

            DB::transaction(function () use ($assignees, $stage, $timer) {
                foreach ($assignees as $user) {
                    $dedupe = 'sla_warning:'.$timer->public_id.':'.$user->public_id;

                    if ($this->alreadyNotified($user, $dedupe)) {
                        continue;
                    }

                    $user->notify(new SlaWarningNotification(
                        taskPublicId: $stage->task->public_id,
                        taskTitleAr: $stage->task->title_ar,
                        taskTitleEn: $stage->task->title_en,
                        stagePublicId: (string) $stage->id,
                        stageNameAr: $stage->blueprintStage->name_ar,
                        stageNameEn: $stage->blueprintStage->name_en,
                        dedupeKey: $dedupe,
                    ));

                    Log::channel('notification')->info('SLA warning notification dispatched', [
                        'tenant_slug' => tenant()?->slug ?? 'central',
                        'action' => 'notification.create',
                        'entity_type' => 'notification',
                        'entity_id' => $dedupe,
                        'performed_by' => 'system',
                        'source_event' => 'SlaWarningTriggered',
                        'recipient' => $user->public_id,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send SLA warning notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'SlaWarningTriggered',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', SlaWarningNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
