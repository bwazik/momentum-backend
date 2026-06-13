<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\StageInstanceCreated;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleStageInstanceCreated
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(StageInstanceCreated $event): void
    {
        try {
            $stageInstance = $event->stageInstance;
            $blueprintStage = $stageInstance->blueprintStage;

            if (! $blueprintStage || ! $blueprintStage->sla_policy_id) {
                return;
            }

            $this->slaTimerService->createTimerForStage($stageInstance);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to handle StageInstanceCreated', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.create_from_event',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $event->stageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
