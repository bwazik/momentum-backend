<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\SubStageInstanceCompleted;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleSubStageInstanceCompleted
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(SubStageInstanceCompleted $event): void
    {
        try {
            $this->slaTimerService->completeTimerForSubStage($event->subStageInstance);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to handle SubStageInstanceCompleted', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.complete_from_event_sub_stage',
                'entity_type' => 'task_sub_stage_instance',
                'entity_id' => $event->subStageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
