<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\SubStageInstanceCreated;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleSubStageInstanceCreated
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(SubStageInstanceCreated $event): void
    {
        try {
            $subStageInstance = $event->subStageInstance;
            $blueprintSubStage = $subStageInstance->blueprintSubStage;

            if (! $blueprintSubStage || ! $blueprintSubStage->sla_policy_id) {
                return;
            }

            $this->slaTimerService->createTimerForSubStage($subStageInstance);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to handle SubStageInstanceCreated', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.create_from_event_sub_stage',
                'entity_type' => 'task_sub_stage_instance',
                'entity_id' => $event->subStageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
