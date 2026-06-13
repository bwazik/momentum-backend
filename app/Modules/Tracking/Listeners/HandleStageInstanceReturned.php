<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\StageInstanceReturned;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleStageInstanceReturned
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(StageInstanceReturned $event): void
    {
        try {
            $stageInstance = $event->returnedStageInstance;

            // Complete the stage timer
            $this->slaTimerService->completeTimerForStage($stageInstance);

            // Complete any active sub-stage timers
            foreach ($stageInstance->subStageInstances as $subStage) {
                $this->slaTimerService->completeTimerForSubStage($subStage);
            }

            // New return target stage timer will be created by HandleStageInstanceCreated
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to handle StageInstanceReturned', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.return_complete',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $event->returnedStageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
