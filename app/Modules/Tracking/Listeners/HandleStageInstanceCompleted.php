<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\StageInstanceCompleted;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleStageInstanceCompleted
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(StageInstanceCompleted $event): void
    {
        try {
            $this->slaTimerService->completeTimerForStage($event->stageInstance);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to handle StageInstanceCompleted', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.complete_from_event',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $event->stageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
